<?php
// Tech service notes written by one staff account (PHP 5.6 Compatible)
//
// Feeds the Staff Accounts panel on the dashboard. Notes are tied to a person
// by the name stamped on them (bucket_technotes.techname), which is the only
// link the table carries - there is no user id on a note.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

/**
 * Keep only a real calendar date in Y-m-d form; everything else becomes ''.
 *
 * @param string $value
 * @return string
 */
function staff_notes_clean_date($value) {
    $value = trim($value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return '';
    }
    if (!checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
        return '';
    }
    return $value;
}

if (!is_tech_logged_in()) {
    echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
    exit;
}

// Staff records are Super Admin (Master) territory - gated here as well as in
// the dashboard UI, so hiding the panel is never the only thing protecting it.
if (!is_super_admin()) {
    echo json_encode(array('success' => false, 'error' => 'Only Super Admin (Master) accounts can view staff service notes.'));
    exit;
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if ($user_id <= 0) {
    echo json_encode(array('success' => false, 'error' => 'No staff account selected.'));
    exit;
}

// One page of notes at a time - the modal pages through the rest.
$per_page = 20;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) {
    $page = 1;
}

// Optional date range on bucket_technotes.xdate (stored as Y-m-d). Anything
// that is not a plain Y-m-d date is ignored rather than passed to the query.
$date_from = staff_notes_clean_date(isset($_GET['date_from']) ? $_GET['date_from'] : '');
$date_to = staff_notes_clean_date(isset($_GET['date_to']) ? $_GET['date_to'] : '');
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $swap = $date_from;
    $date_from = $date_to;
    $date_to = $swap;
}

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(array('success' => false, 'error' => 'Database connection failed'));
    exit;
}

try {
    $stmt_user = $pdo->prepare("SELECT id, user, fname, lname, accesslevel FROM user WHERE id = :uid LIMIT 1");
    $stmt_user->execute(array(':uid' => $user_id));
    $staff = $stmt_user->fetch();

    if (!$staff) {
        echo json_encode(array('success' => false, 'error' => 'That staff account no longer exists.'));
        exit;
    }

    // Super Admin (Master) accounts are kept out of the staff directory, so the
    // API will not serve them either - hidden rows stay unreachable by id.
    $staff_lvl = strtolower(trim($staff['accesslevel']));
    if ($staff_lvl === 'master' || $staff_lvl === 'super admin' || $staff_lvl === 'superadmin') {
        echo json_encode(array('success' => false, 'error' => 'Super Admin (Master) accounts are not part of the staff directory.'));
        exit;
    }

    $full_name = trim($staff['fname'] . ' ' . $staff['lname']);
    if ($full_name === '') {
        $full_name = $staff['user'];
    }

    // Notes are matched by the name stamped on them; the date range narrows it.
    $where = "WHERE LOWER(TRIM(techname)) = :name";
    $params = array(':name' => strtolower($full_name));
    if ($date_from !== '') {
        $where .= " AND xdate >= :date_from";
        $params[':date_from'] = $date_from;
    }
    if ($date_to !== '') {
        $where .= " AND xdate <= :date_to";
        $params[':date_to'] = $date_to;
    }

    // Totals cover the whole filtered set, not just the page being shown.
    $stmt_total = $pdo->prepare("SELECT COUNT(*) AS total_cnt,
            SUM(CASE WHEN LOWER(TRIM(status)) = 'done' THEN 1 ELSE 0 END) AS done_cnt
        FROM bucket_technotes
        " . $where);
    $stmt_total->execute($params);
    $totals = $stmt_total->fetch();

    $total = $totals ? intval($totals['total_cnt']) : 0;
    $done_count = $totals ? intval($totals['done_cnt']) : 0;

    $total_pages = ($total > 0) ? intval(ceil($total / $per_page)) : 1;
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;

    $stmt_notes = $pdo->prepare("SELECT id, accountnum, xdate, clientname, address, techname,
            reasonoftech, causeoftheissue, resso, status, ticket_id
        FROM bucket_technotes
        " . $where . "
        ORDER BY xdate DESC, id DESC
        LIMIT " . intval($per_page) . " OFFSET " . intval($offset));
    $stmt_notes->execute($params);
    $rows = $stmt_notes->fetchAll();

    $notes = array();
    foreach ($rows as $r) {
        $is_pullout = (strpos($r['reasonoftech'], '[Hardware Pull-Out]') !== false);
        $notes[] = array(
            'id' => intval($r['id']),
            'date' => $r['xdate'],
            'accountnum' => $r['accountnum'],
            'client' => !empty($r['clientname']) ? $r['clientname'] : ('Acct #' . $r['accountnum']),
            'address' => $r['address'],
            'reason' => $r['reasonoftech'],
            'cause' => $r['causeoftheissue'],
            'solution' => $r['resso'],
            'status' => $r['status'],
            'status_badge_class' => get_status_badge_class($r['status']),
            'ticket_id' => intval($r['ticket_id']),
            'is_pullout' => $is_pullout
        );
    }

    echo json_encode(array(
        'success' => true,
        'staff' => array(
            'id' => intval($staff['id']),
            'name' => $full_name,
            'username' => $staff['user'],
            'accesslevel' => $staff['accesslevel']
        ),
        'total' => $total,
        'done_count' => $done_count,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
        'showing' => count($notes),
        'range_start' => ($total > 0) ? ($offset + 1) : 0,
        'range_end' => $offset + count($notes),
        'has_prev' => ($page > 1),
        'has_next' => ($page < $total_pages),
        'date_from' => $date_from,
        'date_to' => $date_to,
        'notes' => $notes
    ));
} catch (PDOException $e) {
    error_log("Staff notes API error: " . $e->getMessage());
    echo json_encode(array('success' => false, 'error' => 'Could not load service notes.'));
}
