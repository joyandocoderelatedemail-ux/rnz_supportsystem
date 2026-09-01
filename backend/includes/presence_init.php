<?php
// Staff Presence Tracking for the "Online Now" dashboard panel (PHP 5.6 Compatible)
//
// Only backend accounts from the `user` table (technicians, admins, master)
// are recorded here. The client portal runs on its own separate session and
// never touches this table, so clients can never show up as online.
require_once __DIR__ . '/config.php';

// A staff member counts as online while they loaded a backend page (or their
// dashboard heartbeat fired) within this many minutes.
define('PRESENCE_ONLINE_MINUTES', 5);
// Still signed in but idle - listed separately so nobody is treated as
// available when they have walked away from the desk.
define('PRESENCE_AWAY_MINUTES', 20);
// The notification poll fires every few seconds on every backend page; keep it
// from writing that often while still refreshing well inside the online window.
define('PRESENCE_WRITE_THROTTLE_SECONDS', 30);

/**
 * Create the presence table on first use.
 * @return bool
 */
function init_presence_table() {
    static $done = false;
    if ($done) {
        return true;
    }
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `support_user_presence` (
            `user_id` INT(11) NOT NULL,
            `username` VARCHAR(100) NOT NULL DEFAULT '',
            `fullname` VARCHAR(150) NOT NULL DEFAULT '',
            `accesslevel` VARCHAR(100) NOT NULL DEFAULT '',
            `current_page` VARCHAR(100) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `session_id` VARCHAR(128) NOT NULL DEFAULT '',
            `login_at` DATETIME DEFAULT NULL,
            `last_activity` DATETIME DEFAULT NULL,
            PRIMARY KEY (`user_id`),
            KEY `idx_presence_activity` (`last_activity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        $done = true;
        return true;
    } catch (PDOException $e) {
        error_log("Presence table init error: " . $e->getMessage());
        return false;
    }
}

/**
 * Friendly label for the backend page a staff member is currently viewing.
 * @param string $script_name basename of the running script
 * @return string
 */
function presence_page_label($script_name = '') {
    if ($script_name === '') {
        $script_name = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
    }
    $map = array(
        'index.php'           => 'Dashboard',
        'tickets.php'         => 'Support Tickets',
        'ticket_detail.php'   => 'Ticket Detail',
        'orders.php'          => 'Orders',
        'events.php'          => 'Events & Schedules',
        'accounts.php'        => 'Manage Accounts',
        'inventory.php'       => 'Hardware Inventory',
        'pullout_reports.php' => 'Pull-out Reports',
        'maintenance.php'     => 'POS Maintenance',
        'analytics.php'       => 'Analytics',
        'settings.php'        => 'Admin Settings',
        'technotes.php'       => 'Tech Notes',
        'workorders.php'      => 'Work Orders',
        'hardware_logs.php'   => 'Hardware Logs'
    );
    if (isset($map[$script_name])) {
        return $map[$script_name];
    }
    return 'Support Center';
}

/**
 * Page label to report from a background AJAX poll, which runs on its own
 * script and therefore cannot name the page the staff member is looking at.
 * @return string
 */
function presence_current_page_label() {
    if (!empty($_SESSION['presence_last_page'])) {
        return $_SESSION['presence_last_page'];
    }
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
        if ($path) {
            return presence_page_label(basename($path));
        }
    }
    return 'Support Center';
}

/**
 * Record that the logged-in technician/admin is still active.
 * Safe to call on every backend page load; it only writes once per request.
 *
 * @param string $page_label Override for the page name to display
 * @return bool
 */
function touch_user_presence($page_label = '') {
    static $touched = false;
    if ($touched) {
        return true;
    }

    $tech = get_logged_tech();
    if (!$tech) {
        return false;
    }
    $uid = isset($tech['id']) ? intval($tech['id']) : 0;
    if ($uid <= 0) {
        return false;
    }

    if (!init_presence_table()) {
        return false;
    }
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    if ($page_label === '') {
        $page_label = presence_page_label();
    }

    // Skip repeat writes from the background pollers, but always write when the
    // staff member moved to a different page so the panel stays truthful.
    $same_page = (isset($_SESSION['presence_last_page']) && $_SESSION['presence_last_page'] === $page_label);
    $last_write = isset($_SESSION['presence_last_write']) ? intval($_SESSION['presence_last_write']) : 0;
    if ($same_page && $last_write > 0 && (time() - $last_write) < PRESENCE_WRITE_THROTTLE_SECONDS) {
        $touched = true;
        return true;
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $sid = session_id();
    $now = date('Y-m-d H:i:s');
    $username = isset($tech['user']) ? $tech['user'] : '';
    $fullname = isset($tech['fullname']) ? $tech['fullname'] : '';
    $level = isset($tech['accesslevel']) ? $tech['accesslevel'] : '';

    try {
        $stmt = $pdo->prepare("INSERT INTO `support_user_presence`
            (`user_id`, `username`, `fullname`, `accesslevel`, `current_page`, `ip_address`, `session_id`, `login_at`, `last_activity`)
            VALUES (:uid, :usr, :full, :lvl, :page, :ip, :sid, :now_login, :now_act)
            ON DUPLICATE KEY UPDATE
                `username` = :usr_up,
                `fullname` = :full_up,
                `accesslevel` = :lvl_up,
                `current_page` = :page_up,
                `ip_address` = :ip_up,
                `login_at` = IF(`session_id` <> :sid_cmp, :now_relogin, `login_at`),
                `session_id` = :sid_up,
                `last_activity` = :now_act_up");
        $stmt->execute(array(
            ':uid' => $uid,
            ':usr' => $username,
            ':full' => $fullname,
            ':lvl' => $level,
            ':page' => $page_label,
            ':ip' => $ip,
            ':sid' => $sid,
            ':now_login' => $now,
            ':now_act' => $now,
            ':usr_up' => $username,
            ':full_up' => $fullname,
            ':lvl_up' => $level,
            ':page_up' => $page_label,
            ':ip_up' => $ip,
            ':sid_cmp' => $sid,
            ':sid_up' => $sid,
            ':now_relogin' => $now,
            ':now_act_up' => $now
        ));
        $_SESSION['presence_last_write'] = time();
        $_SESSION['presence_last_page'] = $page_label;
        $touched = true;
        return true;
    } catch (PDOException $e) {
        error_log("Presence update error: " . $e->getMessage());
        return false;
    }
}

/**
 * Drop a staff member from the online list (called on logout).
 * @param int $user_id
 * @return bool
 */
function clear_user_presence($user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return false;
    }
    if (!init_presence_table()) {
        return false;
    }
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM `support_user_presence` WHERE `user_id` = :uid");
        $stmt->execute(array(':uid' => $user_id));
        return true;
    } catch (PDOException $e) {
        error_log("Presence clear error: " . $e->getMessage());
        return false;
    }
}

/**
 * Seconds elapsed since a datetime string (never negative).
 * @param string $datetime
 * @return int
 */
function presence_seconds_since($datetime) {
    if (empty($datetime)) {
        return 999999;
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return 999999;
    }
    $diff = time() - $ts;
    return ($diff < 0) ? 0 : $diff;
}

/**
 * Short last-active label, e.g. "just now", "4m ago".
 * @param string $datetime
 * @return string
 */
function presence_ago($datetime) {
    $secs = presence_seconds_since($datetime);
    if ($secs < 45) {
        return 'just now';
    }
    if ($secs < 3600) {
        $mins = intval(round($secs / 60));
        return $mins . 'm ago';
    }
    $hrs = intval(floor($secs / 3600));
    return $hrs . 'h ago';
}

/**
 * Two-letter avatar initials from a full name.
 * @param string $name
 * @return string
 */
function presence_initials($name) {
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

/**
 * Tailwind classes for the access level pill.
 * @param string $role
 * @return string
 */
function presence_role_badge_class($role) {
    $r = strtolower(trim($role));
    if ($r === 'master' || $r === 'super admin' || $r === 'superadmin') {
        return 'bg-[#FFE8D5] text-[#C32C0B] border-[#FECDAA]';
    }
    if ($r === 'admin' || $r === 'administrator') {
        return 'bg-indigo-50 text-indigo-700 border-indigo-200';
    }
    if (strpos($r, 'programmer') !== false) {
        return 'bg-blue-50 text-blue-700 border-blue-200';
    }
    if (strpos($r, 'tech') !== false || strpos($r, 'support') !== false) {
        return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    }
    return 'bg-slate-100 text-slate-600 border-slate-200';
}

/**
 * Does client_support_tickets carry the chat pop-up in_progress_by column yet?
 * It is added lazily by ticket_chat_init, so a database that has never run the
 * chat must not break the online panel.
 *
 * @param PDO $pdo
 * @return bool
 */
function presence_has_in_progress_column($pdo) {
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `client_support_tickets` LIKE 'in_progress_by'");
        $has = ($stmt && $stmt->fetch()) ? true : false;
    } catch (PDOException $e) {
        $has = false;
    }
    return $has;
}

/**
 * In Progress tickets owned by the given staff names, keyed by lower-cased name.
 * A ticket belongs to whoever it is assigned to; when nobody is assigned it
 * belongs to the technician who moved it to In Progress from the chat, so a
 * ticket is never counted against two people at once.
 *
 * @param array $names full names as stored on the ticket
 * @return array map of lower-cased name => list of tickets
 */
function get_staff_inprogress_tickets($names) {
    $pdo = get_db_connection();
    if (!$pdo || empty($names)) {
        return array();
    }

    $params = array();
    $placeholders = array();
    $seen = array();
    foreach ($names as $n) {
        $key = strtolower(trim($n));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $ph = ':n' . count($placeholders);
        $placeholders[] = $ph;
        $params[$ph] = $key;
    }
    if (empty($placeholders)) {
        return array();
    }

    // Assignment wins; the technician who picked the ticket up only owns it
    // while it sits unassigned. New tickets arrive with the literal string
    // "Unassigned" from the client portal, which is not a person.
    $assigned = "LOWER(TRIM(IFNULL(t.assigned_tech, '')))";
    $owner_expr = presence_has_in_progress_column($pdo)
        ? "CASE WHEN " . $assigned . " NOT IN ('', 'unassigned') THEN " . $assigned . " ELSE LOWER(TRIM(IFNULL(t.in_progress_by, ''))) END"
        : "CASE WHEN " . $assigned . " NOT IN ('', 'unassigned') THEN " . $assigned . " ELSE '' END";

    try {
        $stmt = $pdo->prepare("SELECT t.id, t.ticket_number, t.subject, t.accountnum, t.created_at,
                c.tradename, c.clientname,
                " . $owner_expr . " AS owner_key
            FROM client_support_tickets t
            LEFT JOIN bucket_client c ON t.accountnum = c.accountnum
            WHERE t.status = 'In Progress'
              AND " . $owner_expr . " IN (" . implode(',', $placeholders) . ")
            ORDER BY t.created_at DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Staff workload fetch error: " . $e->getMessage());
        return array();
    }

    $map = array();
    foreach ($rows as $r) {
        $key = $r['owner_key'];
        if (!isset($map[$key])) {
            $map[$key] = array();
        }
        $client = !empty($r['tradename']) ? $r['tradename'] : (!empty($r['clientname']) ? $r['clientname'] : 'Acct: ' . $r['accountnum']);
        $map[$key][] = array(
            'id' => intval($r['id']),
            'ticket_number' => $r['ticket_number'],
            'subject' => $r['subject'],
            'client' => $client
        );
    }
    return $map;
}

/**
 * Fetch staff seen within the away window, most recently active first.
 * Clients are structurally excluded - only `user` accounts are ever stored.
 *
 * @param int $window_minutes How far back to look
 * @return array list of presence rows enriched with state / display fields
 */
function get_online_staff($window_minutes = PRESENCE_AWAY_MINUTES) {
    if (!init_presence_table()) {
        return array();
    }
    $pdo = get_db_connection();
    if (!$pdo) {
        return array();
    }

    $window_minutes = intval($window_minutes);
    if ($window_minutes < 1) {
        $window_minutes = PRESENCE_AWAY_MINUTES;
    }

    $tech = get_logged_tech();
    $my_id = ($tech && isset($tech['id'])) ? intval($tech['id']) : 0;

    // The cut-off is worked out in PHP because last_activity is written with
    // PHP time - measuring it against the database clock would drift by the
    // whole offset wherever MySQL does not run on the application time zone.
    $cutoff = date('Y-m-d H:i:s', time() - ($window_minutes * 60));

    try {
        $stmt = $pdo->prepare("SELECT p.*, u.fname, u.lname, u.emailadd
            FROM `support_user_presence` p
            LEFT JOIN `user` u ON u.id = p.user_id
            WHERE p.last_activity >= :cutoff
            ORDER BY p.last_activity DESC");
        $stmt->execute(array(':cutoff' => $cutoff));
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Presence fetch error: " . $e->getMessage());
        return array();
    }

    // Resolve every display name first, then pull the whole online team's
    // In Progress tickets in one query instead of one per person.
    $names = array();
    foreach ($rows as $i => $r) {
        $name = trim($r['fullname']);
        if ($name === '') {
            $name = trim($r['fname'] . ' ' . $r['lname']);
        }
        if ($name === '') {
            $name = $r['username'];
        }
        $rows[$i]['display_name'] = $name;
        $names[] = $name;
    }
    $workload = get_staff_inprogress_tickets($names);

    $staff = array();
    foreach ($rows as $r) {
        $name = $r['display_name'];
        $work_key = strtolower(trim($name));
        $my_tickets = isset($workload[$work_key]) ? $workload[$work_key] : array();
        $seconds = presence_seconds_since($r['last_activity']);
        $staff[] = array(
            'user_id' => intval($r['user_id']),
            'name' => $name,
            'username' => $r['username'],
            'initials' => presence_initials($name),
            'role' => (trim($r['accesslevel']) !== '') ? $r['accesslevel'] : 'Staff',
            'role_class' => presence_role_badge_class($r['accesslevel']),
            'page' => (trim($r['current_page']) !== '') ? $r['current_page'] : 'Support Center',
            'state' => ($seconds <= PRESENCE_ONLINE_MINUTES * 60) ? 'online' : 'away',
            'seconds_idle' => $seconds,
            'last_seen' => presence_ago($r['last_activity']),
            'online_since' => !empty($r['login_at']) ? date('g:i A', strtotime($r['login_at'])) : '',
            'is_you' => (intval($r['user_id']) === $my_id),
            'in_progress' => $my_tickets,
            'in_progress_count' => count($my_tickets)
        );
    }
    return $staff;
}

/**
 * Count staff by presence state.
 * @param array $staff result of get_online_staff()
 * @return array array('online' => int, 'away' => int)
 */
function count_staff_presence($staff) {
    $counts = array('online' => 0, 'away' => 0);
    foreach ($staff as $s) {
        if ($s['state'] === 'online') {
            $counts['online']++;
        } else {
            $counts['away']++;
        }
    }
    return $counts;
}
