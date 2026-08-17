<?php
// Client Portal Universal Live Search AJAX Endpoint
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(array('success' => false, 'message' => 'Unauthorized'));
    exit;
}

$client = get_logged_client();
$accountnum = $client['accountnum'];
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode(array(
        'success' => true,
        'query' => $query,
        'count' => 0,
        'tickets' => array(),
        'technotes' => array(),
        'workorders' => array(),
        'guides' => array()
    ));
    exit;
}

$pdo = get_db_connection();
$like_q = '%' . $query . '%';

// 1. Search Support Tickets
$stmt_t = $pdo->prepare("SELECT id, ticket_number, subject, category, priority, status, created_at 
    FROM client_support_tickets 
    WHERE accountnum = :acct AND (ticket_number LIKE :q OR subject LIKE :q OR issue_description LIKE :q OR category LIKE :q) 
    ORDER BY id DESC LIMIT 5");
$stmt_t->execute(array(':acct' => $accountnum, ':q' => $like_q));
$tickets_raw = $stmt_t->fetchAll(PDO::FETCH_ASSOC);

$tickets = array();
foreach ($tickets_raw as $t) {
    $tickets[] = array(
        'title' => $t['subject'],
        'subtitle' => 'Ticket #' . $t['ticket_number'] . ' • ' . $t['category'] . ' (' . $t['priority'] . ')',
        'badge' => $t['status'],
        'url' => 'ticket_detail.php?id=' . $t['id'],
        'date' => format_date($t['created_at'])
    );
}

// 2. Search Tech Notes
$stmt_n = $pdo->prepare("SELECT id, xdate, techname, reasonoftech, causeoftheissue, resso, status 
    FROM bucket_technotes 
    WHERE accountnum = :acct AND (reasonoftech LIKE :q OR causeoftheissue LIKE :q OR resso LIKE :q OR techname LIKE :q) 
    ORDER BY id DESC LIMIT 4");
$stmt_n->execute(array(':acct' => $accountnum, ':q' => $like_q));
$notes_raw = $stmt_n->fetchAll(PDO::FETCH_ASSOC);

$technotes = array();
foreach ($notes_raw as $n) {
    $technotes[] = array(
        'title' => !empty($n['reasonoftech']) ? $n['reasonoftech'] : 'Service Visit Log',
        'subtitle' => 'Tech: ' . $n['techname'] . ' • Log #' . $n['id'],
        'badge' => !empty($n['status']) ? $n['status'] : 'Logged',
        'url' => 'technotes.php?q=' . urlencode($query),
        'date' => $n['xdate']
    );
}

// 3. Search Work Orders
$stmt_w = $pdo->prepare("SELECT id, xdate, natureofwork, amount, ornum, status 
    FROM bucket_workorder 
    WHERE accountnum = :acct AND (natureofwork LIKE :q OR ornum LIKE :q) 
    ORDER BY id DESC LIMIT 4");
$stmt_w->execute(array(':acct' => $accountnum, ':q' => $like_q));
$orders_raw = $stmt_w->fetchAll(PDO::FETCH_ASSOC);

$workorders = array();
foreach ($orders_raw as $w) {
    $workorders[] = array(
        'title' => $w['natureofwork'],
        'subtitle' => 'WO #' . $w['id'] . ' • OR #' . $w['ornum'] . ' • ₱' . number_format(floatval($w['amount']), 2),
        'badge' => $w['status'],
        'url' => 'workorders.php?q=' . urlencode($query),
        'date' => $w['xdate']
    );
}

// 4. Search Hardware Knowledge Base Catalog
require_once __DIR__ . '/includes/hardware_data.php';
$guides = array();
if (isset($hardware_devices) && is_array($hardware_devices)) {
    foreach ($hardware_devices as $dev_key => $dev) {
        $name_match = (stripos($dev['name'], $query) !== false);
        $desc_match = (stripos($dev['description'], $query) !== false);
        
        $issue_match = false;
        if (isset($dev['common_issues']) && is_array($dev['common_issues'])) {
            foreach ($dev['common_issues'] as $iss) {
                if (stripos($iss, $query) !== false) {
                    $issue_match = true;
                    break;
                }
            }
        }

        if ($name_match || $desc_match || $issue_match) {
            $guides[] = array(
                'title' => $dev['name'],
                'subtitle' => 'Hardware Diagnostic Wizard & Troubleshooting',
                'badge' => 'Hardware Guide',
                'url' => 'hardware.php?device=' . urlencode($dev_key),
                'date' => 'Interactive'
            );
            if (count($guides) >= 3) {
                break;
            }
        }
    }
}

$total_count = count($tickets) + count($technotes) + count($workorders) + count($guides);

echo json_encode(array(
    'success' => true,
    'query' => $query,
    'count' => $total_count,
    'tickets' => $tickets,
    'technotes' => $technotes,
    'workorders' => $workorders,
    'guides' => $guides
));
exit;
