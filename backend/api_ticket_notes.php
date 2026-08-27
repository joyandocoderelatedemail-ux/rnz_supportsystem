<?php
// Technician Service Notes already logged against a ticket (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/technote_init.php';

init_technote_tables();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_tech_logged_in()) {
    echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(array('success' => false, 'error' => 'Database connection failed'));
    exit;
}

$ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;
if ($ticket_id <= 0) {
    echo json_encode(array('success' => false, 'error' => 'Invalid ticket ID'));
    exit;
}

$stmt_tk = $pdo->prepare("SELECT id, accountnum FROM client_support_tickets WHERE id = :id LIMIT 1");
$stmt_tk->execute(array(':id' => $ticket_id));
$ticket = $stmt_tk->fetch();

if (!$ticket) {
    echo json_encode(array('success' => false, 'error' => 'Ticket not found'));
    exit;
}

function format_technote_row($row) {
    return array(
        'id' => intval($row['id']),
        'xdate' => $row['xdate'],
        'techname' => $row['techname'],
        'reasonoftech' => $row['reasonoftech'],
        'causeoftheissue' => $row['causeoftheissue'],
        'resso' => $row['resso'],
        'status' => $row['status']
    );
}

try {
    // The note logged from this ticket, and only this ticket. Notes for the same
    // client that came from somewhere else are deliberately not returned.
    $stmt_note = $pdo->prepare("SELECT id, xdate, clientname, techname, reasonoftech, causeoftheissue, resso, status
        FROM bucket_technotes WHERE ticket_id = :tid ORDER BY id DESC LIMIT 1");
    $stmt_note->execute(array(':tid' => $ticket_id));
    $row = $stmt_note->fetch();

    echo json_encode(array(
        'success' => true,
        'note' => $row ? format_technote_row($row) : null
    ));
    exit;
} catch (PDOException $e) {
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
    exit;
}
