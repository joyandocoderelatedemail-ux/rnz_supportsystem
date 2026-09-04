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
    $row = get_ticket_service_note($pdo, $ticket_id);

    // Only the technician who logged the note may rewrite it, so the modal is
    // told which it is - the save handler checks the same rule again.
    $tech = get_logged_tech();
    $techname = $tech ? $tech['fullname'] : '';

    echo json_encode(array(
        'success' => true,
        'note' => $row ? format_technote_row($row) : null,
        'can_edit' => can_edit_service_note($row, $techname)
    ));
    exit;
} catch (PDOException $e) {
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
    exit;
}
