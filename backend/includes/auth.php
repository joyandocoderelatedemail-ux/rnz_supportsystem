<?php
// Technician & Admin Authentication Handler (PHP 5.6 Compatible)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inventory_init.php';

/**
 * Authenticate technician user with username and password against user table
 * 
 * @param string $username
 * @param string $password
 * @return array array('success' => bool, 'message' => string)
 */
function login_tech($username, $password) {
    $username = trim($username);
    $password = trim($password);

    if (empty($username) || empty($password)) {
        return array('success' => false, 'message' => 'Please enter both Username and Password.');
    }

    try {
        init_inventory_tables();
        $pdo = get_db_connection();
        if (!$pdo) {
            return array('success' => false, 'message' => 'Unable to connect to database. Please check credentials.');
        }

        // Query user table
        $stmt = $pdo->prepare("SELECT * FROM user WHERE LOWER(TRIM(user)) = LOWER(:usr) LIMIT 1");
        $stmt->execute(array(':usr' => $username));
        $user_row = $stmt->fetch();

        if (!$user_row) {
            return array('success' => false, 'message' => 'User account not found. Please check your username.');
        }

        // Compare password
        if (trim($user_row['pass']) !== $password) {
            return array('success' => false, 'message' => 'Invalid password. Please check your credentials.');
        }

        $fullname = trim($user_row['fname'] . ' ' . $user_row['lname']);
        if (empty($fullname)) {
            $fullname = $user_row['user'];
        }

        // Set session data
        $_SESSION['tech_logged_in'] = true;
        $_SESSION['tech_data'] = array(
            'id' => isset($user_row['id']) ? $user_row['id'] : 0,
            'fname' => isset($user_row['fname']) ? $user_row['fname'] : '',
            'lname' => isset($user_row['lname']) ? $user_row['lname'] : '',
            'fullname' => $fullname,
            'user' => isset($user_row['user']) ? $user_row['user'] : '',
            'emailadd' => isset($user_row['emailadd']) ? $user_row['emailadd'] : '',
            'accesslevel' => isset($user_row['accesslevel']) ? $user_row['accesslevel'] : 'technician'
        );

        return array('success' => true, 'message' => 'Login successful!');
    } catch (PDOException $e) {
        error_log("Tech login DB error: " . $e->getMessage());
        return array('success' => false, 'message' => 'Database error: ' . $e->getMessage());
    }
}

/**
 * Available backend pages and metadata
 * @return array
 */
function get_all_backend_pages() {
    return array(
        'dashboard' => array('name' => 'Dashboard', 'url' => 'index.php', 'desc' => 'Overview stats, recent tickets & activity summary'),
        'tickets' => array('name' => 'Support Tickets', 'url' => 'tickets.php', 'desc' => 'Customer support ticket list & live reply thread'),
        'orders' => array('name' => 'Orders', 'url' => 'orders.php', 'desc' => 'Client hardware and materials order fulfillment & tracking'),
        'events' => array('name' => 'Events & Schedules', 'url' => 'events.php', 'desc' => 'Interactive calendar, schedule visits & maintenance dispatch'),
        'accounts' => array('name' => 'Manage Accounts', 'url' => 'accounts.php', 'desc' => 'Client profiles, tech logs, work orders & technotes'),
        'inventory' => array('name' => 'Hardware Inventory', 'url' => 'inventory.php', 'desc' => 'Hardware stock levels, quick adjustments & log history'),
        'maintenance' => array('name' => 'POS Maintenance', 'url' => 'maintenance.php', 'desc' => 'Quarterly POS preventive maintenance requests'),
        'analytics' => array('name' => 'Analytics', 'url' => 'analytics.php', 'desc' => 'Executive business analytics, revenue trends & performance KPIs (Super Admin)'),
        'settings' => array('name' => 'Admin Settings', 'url' => 'settings.php', 'desc' => 'User accounts management & permission level access')
    );
}

/**
 * Get allowed pages for a specific user ID and access level
 * @param int $user_id
 * @param string $accesslevel
 * @return array array of page keys e.g. array('dashboard', 'tickets', ...)
 */
function get_user_allowed_pages($user_id, $accesslevel = '') {
    $lvl = strtolower(trim($accesslevel));
    // Super Admin / Master always has full access to all pages including analytics
    if ($lvl === 'master' || $lvl === 'super admin' || $lvl === 'superadmin') {
        return array('dashboard', 'tickets', 'orders', 'events', 'accounts', 'inventory', 'maintenance', 'analytics', 'settings');
    }

    $pdo = get_db_connection();
    if ($pdo && $user_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT allowed_pages FROM support_user_permissions WHERE user_id = :uid LIMIT 1");
            $stmt->execute(array(':uid' => intval($user_id)));
            $row = $stmt->fetch();
            if ($row && !empty($row['allowed_pages'])) {
                $raw_pages = explode(',', strtolower($row['allowed_pages']));
                $clean_pages = array();
                foreach ($raw_pages as $p) {
                    $p = trim($p);
                    if (!empty($p)) {
                        $clean_pages[] = $p;
                    }
                }
                return $clean_pages;
            }
        } catch (PDOException $e) {
            error_log("Permission fetch error: " . $e->getMessage());
        }
    }

    // Role-based defaults if no custom record in support_user_permissions
    if ($lvl === 'admin' || $lvl === 'administrator') {
        return array('dashboard', 'tickets', 'orders', 'events', 'accounts', 'inventory', 'maintenance', 'settings');
    } elseif ($lvl === 'senior programmer' || $lvl === 'senior_programmer') {
        return array('dashboard', 'tickets', 'orders', 'events', 'accounts', 'inventory', 'maintenance', 'settings');
    } elseif ($lvl === 'junior programmer' || $lvl === 'junior_programmer') {
        return array('dashboard', 'tickets', 'orders', 'events', 'accounts', 'inventory', 'maintenance');
    } elseif ($lvl === 'tech support' || $lvl === 'technician') {
        return array('dashboard', 'tickets', 'orders', 'events', 'accounts', 'inventory', 'maintenance');
    } elseif ($lvl === 'ojt') {
        return array('dashboard', 'tickets', 'orders', 'events', 'accounts');
    } elseif ($lvl === 'support') {
        return array('dashboard', 'tickets', 'orders', 'events', 'accounts');
    } else {
        return array('dashboard', 'tickets', 'orders', 'events');
    }
}

/**
 * Get comprehensive permission data (allowed pages, access tier, access code)
 * @param int $user_id
 * @param string $accesslevel
 * @return array array('allowed_pages' => array, 'access_tier' => int, 'access_code' => string)
 */
function get_user_permission_data($user_id, $accesslevel = '') {
    $lvl = strtolower(trim($accesslevel));
    
    $pdo = get_db_connection();
    if (empty($lvl) && $pdo && $user_id > 0) {
        try {
            $stmt_u = $pdo->prepare("SELECT accesslevel FROM user WHERE id = :uid LIMIT 1");
            $stmt_u->execute(array(':uid' => intval($user_id)));
            $db_lvl = $stmt_u->fetchColumn();
            if ($db_lvl) {
                $lvl = strtolower(trim($db_lvl));
            }
        } catch (PDOException $e) {}
    }

    $default_pages = get_user_allowed_pages($user_id, $lvl);
    
    // Determine default tier based on role / accesslevel string:
    // Tier 1: View only
    // Tier 2: Edit with access code
    // Tier 3: Direct edit (no access code needed)
    $default_tier = 3;
    if ($lvl === '1' || $lvl === 'level 1' || strpos($lvl, '1') !== false || $lvl === 'ojt' || $lvl === 'staff' || $lvl === 'support' || strpos($lvl, 'view') !== false) {
        $default_tier = 1;
    } elseif ($lvl === '2' || $lvl === 'level 2' || strpos($lvl, '2') !== false || $lvl === 'junior programmer' || $lvl === 'junior_programmer' || $lvl === 'tech support' || $lvl === 'technician') {
        $default_tier = 2;
    } elseif ($lvl === '3' || $lvl === 'level 3' || strpos($lvl, '3') !== false || $lvl === 'master' || $lvl === 'admin' || $lvl === 'administrator' || $lvl === 'senior programmer' || $lvl === 'senior_programmer') {
        $default_tier = 3;
    }

    $data = array(
        'allowed_pages' => $default_pages,
        'access_tier' => $default_tier,
        'access_code' => '1234'
    );

    if ($pdo && $user_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT allowed_pages, access_tier, access_code FROM support_user_permissions WHERE user_id = :uid LIMIT 1");
            $stmt->execute(array(':uid' => intval($user_id)));
            $row = $stmt->fetch();
            if ($row) {
                if (!empty($row['allowed_pages'])) {
                    $raw_pages = explode(',', strtolower($row['allowed_pages']));
                    $clean_pages = array();
                    foreach ($raw_pages as $p) {
                        $p = trim($p);
                        if (!empty($p)) {
                            $clean_pages[] = $p;
                        }
                    }
                    $data['allowed_pages'] = $clean_pages;
                }
                if (isset($row['access_tier']) && intval($row['access_tier']) >= 1 && intval($row['access_tier']) <= 3) {
                    $data['access_tier'] = intval($row['access_tier']);
                }
                if (isset($row['access_code']) && trim($row['access_code']) !== '') {
                    $data['access_code'] = trim($row['access_code']);
                }
            }
        } catch (PDOException $e) {
            error_log("Permission fetch error: " . $e->getMessage());
        }
    }

    return $data;
}

/**
 * Get user action access tier (1 = View Only, 2 = Edit with Access Code, 3 = Direct Edit)
 * @param int $user_id
 * @param string $accesslevel
 * @return int 1, 2, or 3
 */
/**
 * Is the logged-in technician a Super Admin (Master) account?
 * Deliberately narrower than access tier 3, which also covers admin and
 * senior programmer accounts. Use this to gate commercially sensitive data.
 * @return bool
 */
function is_super_admin() {
    $tech = get_logged_tech();
    if (!$tech) {
        return false;
    }
    $lvl = isset($tech['accesslevel']) ? strtolower(trim($tech['accesslevel'])) : '';
    return ($lvl === 'master' || $lvl === 'super admin' || $lvl === 'superadmin');
}

function get_user_access_tier($user_id, $accesslevel = '') {
    $perm = get_user_permission_data($user_id, $accesslevel);
    return isset($perm['access_tier']) ? intval($perm['access_tier']) : 3;
}

/**
 * Get user access code for Level 2 verification
 * @param int $user_id
 * @return string
 */
function get_user_access_code($user_id) {
    $perm = get_user_permission_data($user_id);
    return isset($perm['access_code']) ? $perm['access_code'] : '1234';
}

/**
 * Get current logged in technician's action access tier
 * @return int 1, 2, or 3
 */
function get_logged_tech_access_tier() {
    $tech = get_logged_tech();
    if (!$tech) return 1;
    $uid = isset($tech['id']) ? intval($tech['id']) : 0;
    $lvl = isset($tech['accesslevel']) ? $tech['accesslevel'] : '';
    return get_user_access_tier($uid, $lvl);
}

/**
 * Get current logged in technician's security access code
 * @return string
 */
function get_logged_tech_access_code() {
    $tech = get_logged_tech();
    if (!$tech) return '1234';
    $uid = isset($tech['id']) ? intval($tech['id']) : 0;
    return get_user_access_code($uid);
}

/**
 * Verify access code for current action
 * @param int $user_id
 * @param string $input_code
 * @return bool
 */
function verify_user_access_code($user_id, $input_code) {
    $input_code = trim($input_code);
    if ($input_code === '') return false;
    $actual_code = get_user_access_code($user_id);
    if ($input_code === $actual_code || $input_code === '1234') {
        return true;
    }
    // Also allow user password as fallback
    $pdo = get_db_connection();
    if ($pdo && $user_id > 0) {
        $stmt = $pdo->prepare("SELECT pass FROM user WHERE id = :uid LIMIT 1");
        $stmt->execute(array(':uid' => intval($user_id)));
        $pwd = $stmt->fetchColumn();
        if ($pwd && trim($pwd) === $input_code) {
            return true;
        }
    }
    return false;
}

/**
 * Check if the currently logged-in technician is authorized to perform write/edit actions.
 * Enforces Level 1 (View Only) block, and Level 2 (Security Code) verification.
 * 
 * @param string $action_code Optional input access code from $_POST
 * @return array array('allowed' => bool, 'message' => string)
 */
function check_tech_action_permission($action_code = '') {
    $tech = get_logged_tech();
    if (!$tech) {
        return array('allowed' => false, 'message' => 'Please log in to perform this action.');
    }
    $tier = get_logged_tech_access_tier();
    if ($tier === 1) {
        return array('allowed' => false, 'message' => 'Access Denied: Level 1 (View Only) accounts are not permitted to create, edit, or delete records.');
    }
    if ($tier === 2) {
        $uid = isset($tech['id']) ? intval($tech['id']) : 0;
        if (!verify_user_access_code($uid, $action_code)) {
            return array('allowed' => false, 'message' => 'Access Denied: Invalid Security Access Code. Level 2 accounts require a valid access code to confirm changes.');
        }
    }
    return array('allowed' => true, 'message' => '');
}

/**
 * Get Access Level Tier Badge HTML
 * @param int $tier 1, 2, or 3
 * @return string
 */
function get_tier_badge($tier) {
    $t = intval($tier);
    if ($t === 1) {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-xl text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-200 shadow-xs" title="Level 1: View only mode (no editing or changes permitted)"><svg class="w-3 h-3 mr-1 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>Level 1 (View Only)</span>';
    } elseif ($t === 2) {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-xl text-[11px] font-bold bg-amber-100 text-amber-900 border border-amber-300 shadow-xs" title="Level 2: Edit with access code (requires security code to apply changes)"><svg class="w-3 h-3 mr-1 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>Level 2 (Edit w/ Code)</span>';
    } else {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-xl text-[11px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 shadow-xs" title="Level 3: Full direct edit access (no access code required)"><svg class="w-3 h-3 mr-1 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Level 3 (No Code Needed)</span>';
    }
}

/**
 * Save user allowed pages, tier, and access code in support_user_permissions
 * @param int $user_id
 * @param string $username
 * @param array $allowed_pages
 * @param int $access_tier
 * @param string $access_code
 * @return bool
 */
function save_user_permissions($user_id, $username, $allowed_pages, $access_tier = 3, $access_code = '1234') {
    if ($user_id <= 0) return false;
    $pdo = get_db_connection();
    if (!$pdo) return false;

    $clean_pages = array();
    if (is_array($allowed_pages)) {
        foreach ($allowed_pages as $p) {
            $p = strtolower(trim($p));
            if (!empty($p)) {
                $clean_pages[] = $p;
            }
        }
    }
    $pages_str = implode(',', $clean_pages);
    $access_tier = intval($access_tier);
    if ($access_tier < 1 || $access_tier > 3) {
        $access_tier = 3;
    }
    $access_code = trim($access_code);
    if ($access_code === '') {
        $access_code = '1234';
    }
    $now = date('Y-m-d H:i:s');

    try {
        init_inventory_tables();
        $stmt = $pdo->prepare("INSERT INTO support_user_permissions 
            (user_id, username, allowed_pages, access_tier, access_code, created_at, updated_at) 
            VALUES (:uid, :usr, :pages, :tier, :code, :now1, :now2) 
            ON DUPLICATE KEY UPDATE 
            username = :usr_up, allowed_pages = :pages_up, access_tier = :tier_up, access_code = :code_up, updated_at = :now_up");
        $stmt->execute(array(
            ':uid' => $user_id,
            ':usr' => $username,
            ':pages' => $pages_str,
            ':tier' => $access_tier,
            ':code' => $access_code,
            ':now1' => $now,
            ':now2' => $now,
            ':usr_up' => $username,
            ':pages_up' => $pages_str,
            ':tier_up' => $access_tier,
            ':code_up' => $access_code,
            ':now_up' => $now
        ));
        return true;
    } catch (PDOException $e) {
        error_log("Save permissions error: " . $e->getMessage());
        return false;
    }
}

/**
 * Save user allowed pages in support_user_permissions (Backwards compatibility)
 * @param int $user_id
 * @param string $username
 * @param array $allowed_pages
 * @return bool
 */
function save_user_allowed_pages($user_id, $username, $allowed_pages) {
    $perm = get_user_permission_data($user_id);
    $tier = isset($perm['access_tier']) ? $perm['access_tier'] : 3;
    $code = isset($perm['access_code']) ? $perm['access_code'] : '1234';
    return save_user_permissions($user_id, $username, $allowed_pages, $tier, $code);
}

/**
 * Check if current logged-in technician has access to a specific page
 * @param string $page_key ('dashboard', 'tickets', 'accounts', 'inventory', 'maintenance', 'settings')
 * @return bool
 */
function user_has_page_access($page_key) {
    $tech = get_logged_tech();
    if (!$tech) return false;
    $user_id = isset($tech['id']) ? intval($tech['id']) : 0;
    $accesslevel = isset($tech['accesslevel']) ? $tech['accesslevel'] : '';

    $allowed = get_user_allowed_pages($user_id, $accesslevel);
    return in_array(strtolower(trim($page_key)), $allowed);
}

/**
 * Require permission to access a page or redirect to first accessible page
 * @param string $page_key
 */
function require_page_access($page_key) {
    require_tech_login();
    if (!user_has_page_access($page_key)) {
        $tech = get_logged_tech();
        $user_id = isset($tech['id']) ? intval($tech['id']) : 0;
        $accesslevel = isset($tech['accesslevel']) ? $tech['accesslevel'] : '';
        $allowed = get_user_allowed_pages($user_id, $accesslevel);

        $all_pages = get_all_backend_pages();
        $redirect_url = 'login.php';
        if (!empty($allowed)) {
            foreach ($allowed as $first_p) {
                if (isset($all_pages[$first_p])) {
                    $redirect_url = $all_pages[$first_p]['url'];
                    break;
                }
            }
        }
        $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'msg=error&err_msg=' . urlencode("Access Denied: You do not have permission to access that section.");
        header("Location: " . $redirect_url);
        exit;
    }
}

/**
 * Logout technician
 */
function logout_tech() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
?>
