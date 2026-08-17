<?php
// Support Center Universal Live Search AJAX Endpoint
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_tech_logged_in()) {
    echo json_encode(array('success' => false, 'message' => 'Unauthorized'));
    exit;
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode(array(
        'success' => true,
        'query' => $query,
        'count' => 0,
        'accounts' => array(),
        'tickets' => array(),
        'inventory' => array(),
        'maintenance' => array()
    ));
    exit;
}

$pdo = get_db_connection();
$like_q = '%' . $query . '%';

// 1. Search Client Accounts (bucket_client)
$stmt_a = $pdo->prepare("SELECT accountnum, tradename, clientname, address, contactnum 
    FROM bucket_client 
    WHERE accountnum LIKE :q OR tradename LIKE :q OR clientname LIKE :q OR address LIKE :q 
    ORDER BY accountnum ASC LIMIT 5");
$stmt_a->execute(array(':q' => $like_q));
$accounts_raw = $stmt_a->fetchAll(PDO::FETCH_ASSOC);

$accounts = array();
foreach ($accounts_raw as $a) {
    $accounts[] = array(
        'title' => $a['tradename'],
        'subtitle' => 'Acct #' . $a['accountnum'] . ' • ' . $a['clientname'],
        'badge' => 'Account',
        'url' => 'accounts.php?q=' . urlencode($a['accountnum']),
        'date' => $a['accountnum']
    );
}

// 2. Search Support Tickets (client_support_tickets)
$stmt_t = $pdo->prepare("SELECT id, ticket_number, tradename, subject, category, priority, status, created_at 
    FROM client_support_tickets 
    WHERE ticket_number LIKE :q OR tradename LIKE :q OR subject LIKE :q OR issue_description LIKE :q 
    ORDER BY id DESC LIMIT 5");
$stmt_t->execute(array(':q' => $like_q));
$tickets_raw = $stmt_t->fetchAll(PDO::FETCH_ASSOC);

$tickets = array();
foreach ($tickets_raw as $t) {
    $tickets[] = array(
        'title' => $t['subject'],
        'subtitle' => $t['tradename'] . ' • Ticket #' . $t['ticket_number'] . ' (' . $t['priority'] . ')',
        'badge' => $t['status'],
        'url' => 'ticket_detail.php?id=' . $t['id'],
        'date' => format_date($t['created_at'])
    );
}

// 3. Search Inventory Items (support_inventory_items)
$stmt_i = $pdo->prepare("SELECT id, item_code, name, quantity, min_threshold, unit_price 
    FROM support_inventory_items 
    WHERE item_code LIKE :q OR name LIKE :q OR description LIKE :q 
    ORDER BY name ASC LIMIT 4");
$stmt_i->execute(array(':q' => $like_q));
$inventory_raw = $stmt_i->fetchAll(PDO::FETCH_ASSOC);

$inventory = array();
foreach ($inventory_raw as $i) {
    $inventory[] = array(
        'title' => $i['name'],
        'subtitle' => 'Code: ' . $i['item_code'] . ' • In Stock: ' . $i['quantity'] . ' units',
        'badge' => ($i['quantity'] <= $i['min_threshold']) ? 'Low Stock' : 'In Stock',
        'url' => 'inventory.php?q=' . urlencode($i['name']),
        'date' => '₱' . number_format(floatval($i['unit_price']), 2)
    );
}

// 4. Search POS Maintenance Requests
$stmt_m = $pdo->prepare("SELECT id, request_number, tradename, units_count, preferred_date, status 
    FROM client_maintenance_requests 
    WHERE request_number LIKE :q OR tradename LIKE :q 
    ORDER BY id DESC LIMIT 3");
$stmt_m->execute(array(':q' => $like_q));
$maint_raw = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

$maintenance = array();
foreach ($maint_raw as $m) {
    $maintenance[] = array(
        'title' => 'Maintenance: ' . $m['tradename'],
        'subtitle' => 'Req #' . $m['request_number'] . ' • ' . $m['units_count'] . ' Unit(s)',
        'badge' => $m['status'],
        'url' => 'maintenance.php?q=' . urlencode($m['request_number']),
        'date' => format_date($m['preferred_date'])
    );
}

$total_count = count($accounts) + count($tickets) + count($inventory) + count($maintenance);

echo json_encode(array(
    'success' => true,
    'query' => $query,
    'count' => $total_count,
    'accounts' => $accounts,
    'tickets' => $tickets,
    'inventory' => $inventory,
    'maintenance' => $maintenance
));
exit;
