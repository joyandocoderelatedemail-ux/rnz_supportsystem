<?php
// Support Center Admin Settings & User Access Management (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory_init.php';

// Check access permission for Admin Settings
require_page_access('settings');

$pdo = get_db_connection();
$tech = get_logged_tech();
$tech_id = isset($tech['id']) ? intval($tech['id']) : 0;
$tech_username = isset($tech['user']) ? strtolower($tech['user']) : '';
$current_access = isset($tech['accesslevel']) ? strtolower($tech['accesslevel']) : 'technician';

$all_pages_catalog = get_all_backend_pages();

$msg = isset($_GET['msg']) ? sanitize($_GET['msg']) : '';
$msg_type = 'success';
$msg_text = '';

if ($msg === 'user_created') {
    $msg_text = 'User account and sidebar page permissions created successfully.';
} elseif ($msg === 'user_updated') {
    $msg_text = 'User account and sidebar access permissions updated successfully.';
} elseif ($msg === 'user_deleted') {
    $msg_text = 'User account removed successfully.';
} elseif ($msg === 'error') {
    $msg_type = 'error';
    $msg_text = isset($_GET['err_msg']) ? sanitize($_GET['err_msg']) : 'An error occurred during the operation.';
}

// ----------------------------------------------------
// Handle Form Submissions
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Create New User Account
    if ($action === 'create_user') {
        $fname = isset($_POST['fname']) ? trim($_POST['fname']) : '';
        $lname = isset($_POST['lname']) ? trim($_POST['lname']) : '';
        $username = isset($_POST['user']) ? trim($_POST['user']) : '';
        $password = isset($_POST['pass']) ? trim($_POST['pass']) : '';
        $accesslevel = isset($_POST['accesslevel']) ? trim($_POST['accesslevel']) : 'technician';
        $emailadd = isset($_POST['emailadd']) ? trim($_POST['emailadd']) : 'N/A';
        $contactnum = isset($_POST['contactnum']) ? trim($_POST['contactnum']) : 'N/A';
        $address = isset($_POST['address']) ? trim($_POST['address']) : 'N/A';
        $birthday = isset($_POST['birthday']) ? trim($_POST['birthday']) : 'N/A';
        $allowed_pages = isset($_POST['allowed_pages']) && is_array($_POST['allowed_pages']) ? $_POST['allowed_pages'] : array();

        if (empty($fname) || empty($username) || empty($password)) {
            header("Location: settings?msg=error&err_msg=" . urlencode("First Name, Username, and Password are required."));
            exit;
        }

        // If no pages selected, default based on role
        if (empty($allowed_pages)) {
            $allowed_pages = get_user_allowed_pages(0, $accesslevel);
        }

        try {
            // Check if username already exists
            $stmt_check = $pdo->prepare("SELECT id FROM user WHERE LOWER(TRIM(user)) = LOWER(:usr) LIMIT 1");
            $stmt_check->execute(array(':usr' => $username));
            if ($stmt_check->fetch()) {
                header("Location: settings?msg=error&err_msg=" . urlencode("Username '" . $username . "' already exists. Please choose a different username."));
                exit;
            }

            $stmt_ins = $pdo->prepare("INSERT INTO user 
                (fname, lname, birthday, address, emailadd, contactnum, user, pass, accesslevel) 
                VALUES (:fname, :lname, :bday, :addr, :email, :phone, :usr, :pass, :access)");
            
            $stmt_ins->execute(array(
                ':fname' => $fname,
                ':lname' => $lname,
                ':bday' => !empty($birthday) ? $birthday : 'N/A',
                ':addr' => !empty($address) ? $address : 'N/A',
                ':email' => !empty($emailadd) ? $emailadd : 'N/A',
                ':phone' => !empty($contactnum) ? $contactnum : 'N/A',
                ':usr' => $username,
                ':pass' => $password,
                ':access' => $accesslevel
            ));
            $new_user_id = $pdo->lastInsertId();

            // Save granular sidebar page permissions
            save_user_allowed_pages($new_user_id, $username, $allowed_pages);

            header("Location: settings?msg=user_created");
            exit;
        } catch (PDOException $e) {
            header("Location: settings?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 2. Update Existing User Account
    elseif ($action === 'update_user') {
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $fname = isset($_POST['fname']) ? trim($_POST['fname']) : '';
        $lname = isset($_POST['lname']) ? trim($_POST['lname']) : '';
        $username = isset($_POST['user']) ? trim($_POST['user']) : '';
        $new_password = isset($_POST['new_pass']) ? trim($_POST['new_pass']) : '';
        $accesslevel = isset($_POST['accesslevel']) ? trim($_POST['accesslevel']) : 'technician';
        $emailadd = isset($_POST['emailadd']) ? trim($_POST['emailadd']) : 'N/A';
        $contactnum = isset($_POST['contactnum']) ? trim($_POST['contactnum']) : 'N/A';
        $address = isset($_POST['address']) ? trim($_POST['address']) : 'N/A';
        $birthday = isset($_POST['birthday']) ? trim($_POST['birthday']) : 'N/A';
        $allowed_pages = isset($_POST['allowed_pages']) && is_array($_POST['allowed_pages']) ? $_POST['allowed_pages'] : array();

        if ($user_id <= 0 || empty($fname) || empty($username)) {
            header("Location: settings?msg=error&err_msg=" . urlencode("User ID, First Name, and Username are required."));
            exit;
        }

        try {
            // Check username conflict with other users
            $stmt_check = $pdo->prepare("SELECT id FROM user WHERE LOWER(TRIM(user)) = LOWER(:usr) AND id != :uid LIMIT 1");
            $stmt_check->execute(array(':usr' => $username, ':uid' => $user_id));
            if ($stmt_check->fetch()) {
                header("Location: settings?msg=error&err_msg=" . urlencode("Username '" . $username . "' is already taken by another account."));
                exit;
            }

            if (!empty($new_password)) {
                $stmt_up = $pdo->prepare("UPDATE user SET 
                    fname = :fname, lname = :lname, birthday = :bday, 
                    address = :addr, emailadd = :email, contactnum = :phone, 
                    user = :usr, pass = :pass, accesslevel = :access 
                    WHERE id = :uid");
                $stmt_up->execute(array(
                    ':fname' => $fname,
                    ':lname' => $lname,
                    ':bday' => !empty($birthday) ? $birthday : 'N/A',
                    ':addr' => !empty($address) ? $address : 'N/A',
                    ':email' => !empty($emailadd) ? $emailadd : 'N/A',
                    ':phone' => !empty($contactnum) ? $contactnum : 'N/A',
                    ':usr' => $username,
                    ':pass' => $new_password,
                    ':access' => $accesslevel,
                    ':uid' => $user_id
                ));
            } else {
                $stmt_up = $pdo->prepare("UPDATE user SET 
                    fname = :fname, lname = :lname, birthday = :bday, 
                    address = :addr, emailadd = :email, contactnum = :phone, 
                    user = :usr, accesslevel = :access 
                    WHERE id = :uid");
                $stmt_up->execute(array(
                    ':fname' => $fname,
                    ':lname' => $lname,
                    ':bday' => !empty($birthday) ? $birthday : 'N/A',
                    ':addr' => !empty($address) ? $address : 'N/A',
                    ':email' => !empty($emailadd) ? $emailadd : 'N/A',
                    ':phone' => !empty($contactnum) ? $contactnum : 'N/A',
                    ':usr' => $username,
                    ':access' => $accesslevel,
                    ':uid' => $user_id
                ));
            }

            // Save granular sidebar page permissions
            save_user_allowed_pages($user_id, $username, $allowed_pages);

            // If logged-in user modified themselves, update session
            if ($user_id === $tech_id || strtolower($username) === $tech_username) {
                $_SESSION['tech_data']['fname'] = $fname;
                $_SESSION['tech_data']['lname'] = $lname;
                $_SESSION['tech_data']['fullname'] = trim($fname . ' ' . $lname);
                $_SESSION['tech_data']['user'] = $username;
                $_SESSION['tech_data']['emailadd'] = $emailadd;
                $_SESSION['tech_data']['accesslevel'] = $accesslevel;
            }

            header("Location: settings?msg=user_updated");
            exit;
        } catch (PDOException $e) {
            header("Location: settings?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 3. Delete User Account
    elseif ($action === 'delete_user') {
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        if ($user_id <= 0) {
            header("Location: settings?msg=error&err_msg=" . urlencode("Invalid user ID specified."));
            exit;
        }

        // Prevent deleting own currently active account
        if ($user_id === $tech_id) {
            header("Location: settings?msg=error&err_msg=" . urlencode("You cannot delete your own logged-in account."));
            exit;
        }

        try {
            $stmt_target = $pdo->prepare("SELECT user, accesslevel FROM user WHERE id = :uid LIMIT 1");
            $stmt_target->execute(array(':uid' => $user_id));
            $target_row = $stmt_target->fetch();

            if (!$target_row) {
                header("Location: settings?msg=error&err_msg=" . urlencode("User account not found."));
                exit;
            }

            if (strtolower($target_row['user']) === $tech_username) {
                header("Location: settings?msg=error&err_msg=" . urlencode("You cannot delete your own logged-in account."));
                exit;
            }

            // Check if this is the only master admin
            if (strtolower($target_row['accesslevel']) === 'master') {
                $stmt_m_cnt = $pdo->query("SELECT COUNT(*) FROM user WHERE LOWER(accesslevel) = 'master'");
                $m_cnt = intval($stmt_m_cnt->fetchColumn());
                if ($m_cnt <= 1) {
                    header("Location: settings?msg=error&err_msg=" . urlencode("Cannot delete the last Super Admin (Master) account."));
                    exit;
                }
            }

            $stmt_del = $pdo->prepare("DELETE FROM user WHERE id = :uid");
            $stmt_del->execute(array(':uid' => $user_id));

            // Clean up custom permissions entry
            $pdo->prepare("DELETE FROM support_user_permissions WHERE user_id = :uid")->execute(array(':uid' => $user_id));

            header("Location: settings?msg=user_deleted");
            exit;
        } catch (PDOException $e) {
            header("Location: settings?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }
}

// ----------------------------------------------------
// Query Setup & Filtering
// ----------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? trim($_GET['role']) : '';

$where_clauses = array("1=1");
$params = array();

if (!empty($search)) {
    $where_clauses[] = "(fname LIKE :s1 OR lname LIKE :s2 OR user LIKE :s3 OR emailadd LIKE :s4 OR contactnum LIKE :s5)";
    $params[':s1'] = "%" . $search . "%";
    $params[':s2'] = "%" . $search . "%";
    $params[':s3'] = "%" . $search . "%";
    $params[':s4'] = "%" . $search . "%";
    $params[':s5'] = "%" . $search . "%";
}

if (!empty($role_filter)) {
    $where_clauses[] = "LOWER(accesslevel) = LOWER(:rf)";
    $params[':rf'] = $role_filter;
}

$where_sql = implode(" AND ", $where_clauses);
$stmt_users = $pdo->prepare("SELECT * FROM user WHERE $where_sql ORDER BY accesslevel ASC, fname ASC");
$stmt_users->execute($params);
$users_list = $stmt_users->fetchAll();

// KPI Stats
$total_users_cnt = intval($pdo->query("SELECT COUNT(*) FROM user")->fetchColumn());
$master_admin_cnt = intval($pdo->query("SELECT COUNT(*) FROM user WHERE LOWER(accesslevel) IN ('master', 'admin')")->fetchColumn());
$tech_users_cnt = intval($pdo->query("SELECT COUNT(*) FROM user WHERE LOWER(accesslevel) = 'technician'")->fetchColumn());
$support_users_cnt = intval($pdo->query("SELECT COUNT(*) FROM user WHERE LOWER(accesslevel) NOT IN ('master', 'admin', 'technician')")->fetchColumn());

/**
 * Helper to get access level badge style
 */
function get_role_badge($level) {
    $lvl = strtolower(trim($level));
    if ($lvl === 'master') {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-purple-100 text-purple-800 border border-purple-200 shadow-xs">Super Admin (Master)</span>';
    } elseif ($lvl === 'admin') {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-[#FFE8D5] text-[#EB3E0B] border border-[#FECDAA] shadow-xs">Administrator</span>';
    } elseif ($lvl === 'technician') {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 shadow-xs">Support Tech</span>';
    } elseif ($lvl === 'support') {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-800 border border-blue-200 shadow-xs">Support Agent</span>';
    } else {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200 shadow-xs">' . sanitize(ucwords($level)) . '</span>';
    }
}

$active_page = 'settings';
$page_title = 'Admin Settings & Page Permissions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings - Sidebar Permissions & User Access | RNZ Support Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased selection:bg-[#EB3E0B] selection:text-white">

<div class="flex min-h-screen">
    <!-- Sidebar -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Container -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <!-- Page Content -->
        <main class="flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto space-y-6 pb-20">

            <!-- Flash Message -->
            <?php if (!empty($msg_text)): ?>
                <div class="p-4 rounded-2xl flex items-center justify-between border <?php echo ($msg_type === 'success') ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-rose-50 text-rose-800 border-rose-200'; ?> animate-in fade-in duration-200 shadow-sm">
                    <div class="flex items-center space-x-3 text-xs sm:text-sm font-semibold">
                        <?php if ($msg_type === 'success'): ?>
                            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <?php else: ?>
                            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?php endif; ?>
                        <span><?php echo $msg_text; ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-xs font-bold opacity-60 hover:opacity-100">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Page Title & Create Account Action -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
                <div>
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold shadow-md shadow-[#EB3E0B]/20">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-xl font-extrabold text-slate-900 tracking-tight">Admin Settings & Sidebar Page Access</h2>
                            <p class="text-xs text-slate-500">Create user accounts, set credentials, and assign exactly what sidebar pages each user can access.</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center space-x-3 shrink-0">
                    <button type="button" onclick="openCreateUserModal()" class="w-full sm:w-auto bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-5 py-3 rounded-2xl shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95 flex items-center justify-center space-x-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                        <span>Create New Account</span>
                    </button>
                </div>
            </div>

            <!-- KPI Metric Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Total Users -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Accounts</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($total_users_cnt); ?></h3>
                        <p class="text-[11px] text-slate-400">System registered users</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                </div>

                <!-- Master & Administrators -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-purple-600 uppercase tracking-wider">Admins & Masters</span>
                        <h3 class="text-3xl font-extrabold text-purple-700 font-mono"><?php echo number_format($master_admin_cnt); ?></h3>
                        <p class="text-[11px] text-slate-400">Full control privileges</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                </div>

                <!-- Support Technicians -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Technicians</span>
                        <h3 class="text-3xl font-extrabold text-emerald-600 font-mono"><?php echo number_format($tech_users_cnt); ?></h3>
                        <p class="text-[11px] text-slate-400">Ticket & hardware staff</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        </svg>
                    </div>
                </div>

                <!-- Current User Role -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1 overflow-hidden">
                        <span class="text-xs font-bold text-[#EB3E0B] uppercase tracking-wider">Your Active Role</span>
                        <h3 class="text-base font-extrabold text-slate-900 truncate"><?php echo sanitize($tech['fullname']); ?></h3>
                        <div><?php echo get_role_badge($current_access); ?></div>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filters & Search Bar -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 sm:p-6 space-y-4">
                <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                    <!-- Search Input -->
                    <div class="sm:col-span-6 relative">
                        <input type="text" name="search" value="<?php echo sanitize($search); ?>" placeholder="Search by name, username, email, phone..." class="w-full bg-slate-50 text-slate-800 text-xs pl-10 pr-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all placeholder-slate-400">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <!-- Role Filter -->
                    <div class="sm:col-span-4">
                        <select name="role" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all font-semibold">
                            <option value="" <?php echo empty($role_filter) ? 'selected' : ''; ?>>All Access Levels</option>
                            <option value="master" <?php echo ($role_filter === 'master') ? 'selected' : ''; ?>>Super Admin (Master)</option>
                            <option value="admin" <?php echo ($role_filter === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                            <option value="technician" <?php echo ($role_filter === 'technician') ? 'selected' : ''; ?>>Support Technician</option>
                            <option value="support" <?php echo ($role_filter === 'support') ? 'selected' : ''; ?>>Support Agent</option>
                            <option value="staff" <?php echo ($role_filter === 'staff') ? 'selected' : ''; ?>>Staff / Viewer</option>
                        </select>
                    </div>

                    <!-- Submit & Reset -->
                    <div class="sm:col-span-2 flex items-center gap-2">
                        <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs py-3 px-4 rounded-2xl transition-all shadow-sm">
                            Filter
                        </button>
                        <?php if (!empty($search) || !empty($role_filter)): ?>
                            <a href="settings.php" class="p-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-2xl text-xs font-bold transition-all" title="Reset Filters">
                                &times;
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Users List Table -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden space-y-4">
                <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-900">User Accounts & Sidebar Permissions</h3>
                        <p class="text-xs text-slate-500">Assign what pages each technician or administrator can access in the navigation.</p>
                    </div>
                    <span class="text-xs text-slate-400 font-mono"><?php echo count($users_list); ?> account(s) found</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3.5 px-6">Name & Details</th>
                                <th class="py-3.5 px-6">Username</th>
                                <th class="py-3.5 px-6 text-center">Access Role</th>
                                <th class="py-3.5 px-6">Allowed Sidebar Pages</th>
                                <th class="py-3.5 px-6">Contact / Email</th>
                                <th class="py-3.5 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php if (empty($users_list)): ?>
                                <tr>
                                    <td colspan="6" class="py-12 text-center text-slate-400 space-y-3">
                                        <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                        </div>
                                        <p class="font-bold text-slate-600">No user accounts found matching your filter.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users_list as $u): 
                                    $fullname = trim($u['fname'] . ' ' . $u['lname']);
                                    if (empty($fullname)) {
                                        $fullname = $u['user'];
                                    }
                                    $initial = strtoupper(substr($fullname, 0, 1));
                                    $is_self = ($u['id'] == $tech_id) || (strtolower($u['user']) === $tech_username);
                                    $user_pages = get_user_allowed_pages($u['id'], $u['accesslevel']);
                                ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <!-- Name & Avatar -->
                                        <td class="py-4 px-6">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-9 h-9 rounded-2xl bg-slate-100 border border-slate-200 text-slate-700 font-extrabold text-xs flex items-center justify-center shrink-0">
                                                    <?php echo $initial; ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <h4 class="font-extrabold text-slate-900 text-sm truncate flex items-center space-x-1.5">
                                                        <span><?php echo sanitize($fullname); ?></span>
                                                        <?php if ($is_self): ?>
                                                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#FFE8D5] text-[#EB3E0B] border border-[#FECDAA]">You</span>
                                                        <?php endif; ?>
                                                    </h4>
                                                    <?php if (!empty($u['address']) && $u['address'] !== 'NA' && $u['address'] !== 'N/A' && $u['address'] !== 'Admin' && $u['address'] !== 'Edgar'): ?>
                                                        <span class="text-[11px] text-slate-400 block truncate max-w-xs"><?php echo sanitize($u['address']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Username -->
                                        <td class="py-4 px-6">
                                            <span class="font-mono font-bold text-xs bg-slate-100 text-slate-800 px-2.5 py-1 rounded-xl border border-slate-200">
                                                @<?php echo sanitize($u['user']); ?>
                                            </span>
                                        </td>

                                        <!-- Access Level Role -->
                                        <td class="py-4 px-6 text-center">
                                            <?php echo get_role_badge($u['accesslevel']); ?>
                                        </td>

                                        <!-- Allowed Sidebar Pages -->
                                        <td class="py-4 px-6">
                                            <div class="flex flex-wrap gap-1 max-w-xs">
                                                <?php foreach ($all_pages_catalog as $p_key => $p_info): 
                                                    $has_p = in_array($p_key, $user_pages);
                                                ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold <?php echo $has_p ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-400 line-through opacity-50'; ?>" title="<?php echo $has_p ? 'Has access to ' . $p_info['name'] : 'No access'; ?>">
                                                        <?php echo $p_info['name']; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>

                                        <!-- Contact / Email -->
                                        <td class="py-4 px-6 space-y-0.5">
                                            <?php if (!empty($u['emailadd']) && $u['emailadd'] !== 'NA' && $u['emailadd'] !== 'N/A' && $u['emailadd'] !== 'Admin' && $u['emailadd'] !== 'Edgar'): ?>
                                                <div class="text-xs text-slate-700 flex items-center space-x-1.5">
                                                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                                    <span><?php echo sanitize($u['emailadd']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($u['contactnum']) && $u['contactnum'] !== 'NA' && $u['contactnum'] !== 'N/A' && $u['contactnum'] !== 'Admin' && $u['contactnum'] !== 'Edgar'): ?>
                                                <div class="text-xs text-slate-500 font-mono flex items-center space-x-1.5">
                                                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                                    <span><?php echo sanitize($u['contactnum']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (empty($u['emailadd']) && empty($u['contactnum'])): ?>
                                                <span class="text-slate-400 italic text-[11px]">No contact details</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td class="py-4 px-6 text-right">
                                            <div class="flex items-center justify-end space-x-2">
                                                <!-- Edit Button -->
                                                <button type="button" onclick='openEditUserModal(<?php echo json_encode($u); ?>, <?php echo json_encode($user_pages); ?>)' class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs flex items-center space-x-1.5 transition-colors" title="Edit User & Permissions">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                    <span>Edit</span>
                                                </button>

                                                <!-- Delete Button -->
                                                <?php if (!$is_self): ?>
                                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete user @<?php echo addslashes($u['user']); ?> (<?php echo addslashes($fullname); ?>)? This action cannot be undone.');" class="inline">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <button type="submit" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-rose-100 text-slate-500 hover:text-rose-600 flex items-center justify-center transition-colors" title="Delete User">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: CREATE NEW USER ACCOUNT -->
<!-- ========================================================================= -->
<div id="createUserModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-2xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold shadow-md shadow-[#EB3E0B]/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Create New User Account</h3>
                    <p class="text-xs text-slate-500">Register new personnel and assign sidebar page access.</p>
                </div>
            </div>
            <button type="button" onclick="closeCreateUserModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form action="" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="create_user">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- First Name -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">First Name <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="fname" required placeholder="e.g. John" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Last Name -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Last Name</label>
                    <input type="text" name="lname" placeholder="e.g. Doe" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Username -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Username <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="user" required placeholder="e.g. jdoe" autocomplete="off" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Password -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Password <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="pass" required placeholder="Enter temporary password" autocomplete="off" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Access Level Role -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Primary Role / Profile <span class="text-[#EB3E0B]">*</span></label>
                    <select name="accesslevel" id="create_accesslevel" onchange="applyRolePreset('create', this.value)" required class="w-full bg-slate-50 text-slate-900 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-semibold">
                        <option value="technician" selected>Support Tech (Hardware, Tickets, Diagnostic logs & Inventory)</option>
                        <option value="admin">Administrator (Full Access to Support Hub & Accounts)</option>
                        <option value="master">Super Admin / Master (Full administrative ownership & User management)</option>
                        <option value="support">Support Agent (Tickets & Communications)</option>
                        <option value="staff">Staff / Viewer (Read-only)</option>
                    </select>
                </div>

                <!-- Granular Sidebar Page Permissions Checkboxes -->
                <div class="sm:col-span-2 space-y-2 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-extrabold text-slate-900">Allowed Sidebar Pages & Sections</label>
                        <div class="flex items-center space-x-2 text-[11px]">
                            <button type="button" onclick="setAllPages('create', true)" class="text-[#EB3E0B] hover:underline font-bold">Select All</button>
                            <span class="text-slate-300">|</span>
                            <button type="button" onclick="setAllPages('create', false)" class="text-slate-500 hover:underline">Clear</button>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 mb-2">Check each sidebar section that this user is permitted to see and open:</p>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <?php foreach ($all_pages_catalog as $p_key => $p_info): 
                            $default_checked = ($p_key !== 'settings');
                        ?>
                            <label class="flex items-start space-x-3 p-2.5 rounded-xl bg-white border border-slate-200 hover:border-slate-300 cursor-pointer transition-colors shadow-xs">
                                <input type="checkbox" name="allowed_pages[]" value="<?php echo $p_key; ?>" id="create_page_<?php echo $p_key; ?>" <?php echo $default_checked ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 text-[#EB3E0B] rounded border-slate-300 focus:ring-[#EB3E0B]">
                                <div class="min-w-0">
                                    <span class="text-xs font-bold text-slate-800 block"><?php echo $p_info['name']; ?></span>
                                    <span class="text-[10px] text-slate-400 block leading-tight"><?php echo $p_info['desc']; ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Email Address -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Email Address</label>
                    <input type="email" name="emailadd" placeholder="name@company.com" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Contact Number -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Contact Number</label>
                    <input type="text" name="contactnum" placeholder="09xxxxxxxxx" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Address -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Location / Branch</label>
                    <input type="text" name="address" placeholder="Branch or office location" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Birthday -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Birthday (Optional)</label>
                    <input type="text" name="birthday" placeholder="YYYY-MM-DD or Month Day" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeCreateUserModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-2xl bg-[#EB3E0B] text-white font-bold text-xs hover:bg-[#C32C0B] shadow-md shadow-[#EB3E0B]/25 transition-all">
                    Create User Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: EDIT USER ACCOUNT & ACCESS LEVEL -->
<!-- ========================================================================= -->
<div id="editUserModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-2xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-slate-900 text-white flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Edit User & Sidebar Access</h3>
                    <p class="text-xs text-slate-500">Update credentials and specify allowed sidebar pages.</p>
                </div>
            </div>
            <button type="button" onclick="closeEditUserModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form action="" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="edit_user_id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- First Name -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">First Name <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="fname" id="edit_fname" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Last Name -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Last Name</label>
                    <input type="text" name="lname" id="edit_lname" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Username -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Username <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="user" id="edit_user" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- New Password (Optional) -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Change Password (Optional)</label>
                    <input type="text" name="new_pass" id="edit_new_pass" placeholder="Leave blank to keep unchanged" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Access Level Role -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Primary Role / Profile <span class="text-[#EB3E0B]">*</span></label>
                    <select name="accesslevel" id="edit_accesslevel" onchange="applyRolePreset('edit', this.value)" required class="w-full bg-slate-50 text-slate-900 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-semibold">
                        <option value="technician">Support Tech (Hardware, Tickets, Diagnostic logs & Inventory)</option>
                        <option value="admin">Administrator (Full Access to Support Hub & Accounts)</option>
                        <option value="master">Super Admin / Master (Full administrative ownership & User management)</option>
                        <option value="support">Support Agent (Tickets & Communications)</option>
                        <option value="staff">Staff / Viewer (Read-only)</option>
                    </select>
                </div>

                <!-- Granular Sidebar Page Permissions Checkboxes -->
                <div class="sm:col-span-2 space-y-2 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-extrabold text-slate-900">Allowed Sidebar Pages & Sections</label>
                        <div class="flex items-center space-x-2 text-[11px]">
                            <button type="button" onclick="setAllPages('edit', true)" class="text-[#EB3E0B] hover:underline font-bold">Select All</button>
                            <span class="text-slate-300">|</span>
                            <button type="button" onclick="setAllPages('edit', false)" class="text-slate-500 hover:underline">Clear</button>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 mb-2">Check each sidebar section that this user is permitted to see and open:</p>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <?php foreach ($all_pages_catalog as $p_key => $p_info): ?>
                            <label class="flex items-start space-x-3 p-2.5 rounded-xl bg-white border border-slate-200 hover:border-slate-300 cursor-pointer transition-colors shadow-xs">
                                <input type="checkbox" name="allowed_pages[]" value="<?php echo $p_key; ?>" id="edit_page_<?php echo $p_key; ?>" class="mt-0.5 w-4 h-4 text-[#EB3E0B] rounded border-slate-300 focus:ring-[#EB3E0B]">
                                <div class="min-w-0">
                                    <span class="text-xs font-bold text-slate-800 block"><?php echo $p_info['name']; ?></span>
                                    <span class="text-[10px] text-slate-400 block leading-tight"><?php echo $p_info['desc']; ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Email Address -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Email Address</label>
                    <input type="email" name="emailadd" id="edit_emailadd" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Contact Number -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Contact Number</label>
                    <input type="text" name="contactnum" id="edit_contactnum" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Address -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Location / Branch</label>
                    <input type="text" name="address" id="edit_address" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Birthday -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Birthday</label>
                    <input type="text" name="birthday" id="edit_birthday" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeEditUserModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-2xl bg-slate-900 text-white font-bold text-xs hover:bg-slate-800 shadow-md transition-all">
                    Update User Account
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var pageKeys = ['dashboard', 'tickets', 'accounts', 'inventory', 'maintenance', 'settings'];

function setAllPages(prefix, checkVal) {
    pageKeys.forEach(function(k) {
        var el = document.getElementById(prefix + '_page_' + k);
        if (el) el.checked = checkVal;
    });
}

function applyRolePreset(prefix, role) {
    role = (role || '').toLowerCase();
    if (role === 'master' || role === 'admin') {
        setAllPages(prefix, true);
    } else if (role === 'technician') {
        setAllPages(prefix, false);
        ['dashboard', 'tickets', 'accounts', 'inventory', 'maintenance'].forEach(function(k) {
            var el = document.getElementById(prefix + '_page_' + k);
            if (el) el.checked = true;
        });
    } else if (role === 'support') {
        setAllPages(prefix, false);
        ['dashboard', 'tickets', 'accounts'].forEach(function(k) {
            var el = document.getElementById(prefix + '_page_' + k);
            if (el) el.checked = true;
        });
    } else {
        setAllPages(prefix, false);
        ['dashboard', 'tickets'].forEach(function(k) {
            var el = document.getElementById(prefix + '_page_' + k);
            if (el) el.checked = true;
        });
    }
}

function openCreateUserModal() {
    var m = document.getElementById('createUserModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeCreateUserModal() {
    var m = document.getElementById('createUserModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function openEditUserModal(userObj, userPages) {
    if (!userObj) return;
    document.getElementById('edit_user_id').value = userObj.id || '';
    document.getElementById('edit_fname').value = userObj.fname || '';
    document.getElementById('edit_lname').value = userObj.lname || '';
    document.getElementById('edit_user').value = userObj.user || '';
    document.getElementById('edit_new_pass').value = '';
    
    var roleVal = (userObj.accesslevel || 'technician').toLowerCase();
    var roleSelect = document.getElementById('edit_accesslevel');
    if (roleSelect) {
        roleSelect.value = roleVal;
        if (roleSelect.selectedIndex === -1) {
            roleSelect.value = 'technician';
        }
    }

    // Set page permission checkboxes
    var allowedArr = Array.isArray(userPages) ? userPages : [];
    pageKeys.forEach(function(k) {
        var el = document.getElementById('edit_page_' + k);
        if (el) {
            el.checked = (roleVal === 'master') || allowedArr.indexOf(k) !== -1;
        }
    });

    document.getElementById('edit_emailadd').value = (userObj.emailadd === 'NA' || userObj.emailadd === 'N/A' || userObj.emailadd === 'Admin') ? '' : (userObj.emailadd || '');
    document.getElementById('edit_contactnum').value = (userObj.contactnum === 'NA' || userObj.contactnum === 'N/A' || userObj.contactnum === 'Admin') ? '' : (userObj.contactnum || '');
    document.getElementById('edit_address').value = (userObj.address === 'NA' || userObj.address === 'N/A' || userObj.address === 'Admin') ? '' : (userObj.address || '');
    document.getElementById('edit_birthday').value = (userObj.birthday === 'NA' || userObj.birthday === 'N/A' || userObj.birthday === 'Admin') ? '' : (userObj.birthday || '');

    var m = document.getElementById('editUserModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeEditUserModal() {
    var m = document.getElementById('editUserModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

// Close modals when clicking backdrop
window.addEventListener('click', function(e) {
    var createM = document.getElementById('createUserModal');
    var editM = document.getElementById('editUserModal');
    if (e.target === createM) closeCreateUserModal();
    if (e.target === editM) closeEditUserModal();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
