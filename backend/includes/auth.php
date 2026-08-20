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
        'accounts' => array('name' => 'Manage Accounts', 'url' => 'accounts.php', 'desc' => 'Client profiles, tech logs, work orders & technotes'),
        'inventory' => array('name' => 'Hardware Inventory', 'url' => 'inventory.php', 'desc' => 'Hardware stock levels, quick adjustments & log history'),
        'maintenance' => array('name' => 'POS Maintenance', 'url' => 'maintenance.php', 'desc' => 'Quarterly POS preventive maintenance requests'),
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
    // Super Admin / Master always has full access to all pages
    if ($lvl === 'master') {
        return array('dashboard', 'tickets', 'accounts', 'inventory', 'maintenance', 'settings');
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
        return array('dashboard', 'tickets', 'accounts', 'inventory', 'maintenance', 'settings');
    } elseif ($lvl === 'senior programmer' || $lvl === 'senior_programmer') {
        return array('dashboard', 'tickets', 'accounts', 'inventory', 'maintenance', 'settings');
    } elseif ($lvl === 'junior programmer' || $lvl === 'junior_programmer') {
        return array('dashboard', 'tickets', 'accounts', 'inventory', 'maintenance');
    } elseif ($lvl === 'tech support' || $lvl === 'technician') {
        return array('dashboard', 'tickets', 'accounts', 'inventory', 'maintenance');
    } elseif ($lvl === 'ojt') {
        return array('dashboard', 'tickets', 'accounts');
    } elseif ($lvl === 'support') {
        return array('dashboard', 'tickets', 'accounts');
    } else {
        return array('dashboard', 'tickets');
    }
}

/**
 * Save user allowed pages in support_user_permissions
 * @param int $user_id
 * @param string $username
 * @param array $allowed_pages
 * @return bool
 */
function save_user_allowed_pages($user_id, $username, $allowed_pages) {
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
    $now = date('Y-m-d H:i:s');

    try {
        init_inventory_tables();
        $stmt = $pdo->prepare("INSERT INTO support_user_permissions 
            (user_id, username, allowed_pages, created_at, updated_at) 
            VALUES (:uid, :usr, :pages, :now1, :now2) 
            ON DUPLICATE KEY UPDATE 
            username = :usr_up, allowed_pages = :pages_up, updated_at = :now_up");
        $stmt->execute(array(
            ':uid' => $user_id,
            ':usr' => $username,
            ':pages' => $pages_str,
            ':now1' => $now,
            ':now2' => $now,
            ':usr_up' => $username,
            ':pages_up' => $pages_str,
            ':now_up' => $now
        ));
        return true;
    } catch (PDOException $e) {
        error_log("Save permissions error: " . $e->getMessage());
        return false;
    }
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
