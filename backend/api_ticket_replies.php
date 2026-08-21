<?php
// Support Center Technician Real-Time Ticket Chat API (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/hardware_data.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_tech_logged_in()) {
    echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
    exit;
}

$tech = get_logged_tech();
$tech_name = isset($tech['fullname']) ? $tech['fullname'] : 'Support Tech';

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(array('success' => false, 'error' => 'Database connection failed'));
    exit;
}

$ticket_id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
if ($ticket_id <= 0) {
    echo json_encode(array('success' => false, 'error' => 'Invalid ticket ID'));
    exit;
}

$stmt_check = $pdo->prepare("SELECT id, ticket_number, status, priority, category, assigned_tech, updated_at FROM client_support_tickets WHERE id = :id LIMIT 1");
$stmt_check->execute(array(':id' => $ticket_id));
$ticket = $stmt_check->fetch();

if (!$ticket) {
    echo json_encode(array('success' => false, 'error' => 'Ticket not found'));
    exit;
}

// -----------------------------------------------------------
// 1. POST: Send Tech Reply via AJAX
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_tech_reply') {
    if (isset($ticket['status']) && in_array($ticket['status'], array('Resolved', 'Closed'))) {
        echo json_encode(array('success' => false, 'error' => 'This ticket is marked as ' . $ticket['status'] . '. Please change the ticket status to In Progress to resume communication.'));
        exit;
    }

    $reply_msg = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';
    $photo_attachments = upload_ticket_photos('attachments');

    if (empty($reply_msg) && empty($photo_attachments)) {
        echo json_encode(array('success' => false, 'error' => 'Please enter a reply message or attach photo(s).'));
        exit;
    }

    $now = date('Y-m-d H:i:s');
    try {
        $stmt_rep = $pdo->prepare("INSERT INTO client_ticket_replies (ticket_id, sender_type, sender_name, message, attachment_path, created_at) VALUES (:tid, 'support', :sname, :msg, :att, :c_at)");
        $stmt_rep->execute(array(
            ':tid' => $ticket_id,
            ':sname' => $tech_name,
            ':msg' => $reply_msg,
            ':att' => $photo_attachments ? $photo_attachments : null,
            ':c_at' => $now
        ));
        $new_reply_id = $pdo->lastInsertId();

        // Auto update status to In Progress if currently Pending
        $pdo->prepare("UPDATE client_support_tickets SET status = 'In Progress', updated_at = :now WHERE id = :tid AND status = 'Pending'")
            ->execute(array(':now' => $now, ':tid' => $ticket_id));

        $parsed_attachments = parse_ticket_attachments($photo_attachments);

        echo json_encode(array(
            'success' => true,
            'reply' => array(
                'id' => intval($new_reply_id),
                'sender_type' => 'support',
                'sender_name' => $tech_name,
                'is_tech' => true,
                'message' => $reply_msg,
                'attachment_path' => $photo_attachments ? $photo_attachments : null,
                'attachments' => $parsed_attachments,
                'formatted_date' => format_date($now),
                'diagnostic_log' => (strpos($reply_msg, '=== HARDWARE DIAGNOSTIC LOG ===') !== false) ? format_diagnostic_log_text($reply_msg) : null
            )
        ));
        exit;
    } catch (PDOException $e) {
        echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        exit;
    }
}

// -----------------------------------------------------------
// 2. GET: Poll for New Replies
// -----------------------------------------------------------
$after_id = isset($_GET['after_id']) ? intval($_GET['after_id']) : 0;

try {
    $stmt_replies = $pdo->prepare("SELECT id, ticket_id, sender_type, sender_name, message, attachment_path, created_at FROM client_ticket_replies WHERE ticket_id = :tid AND id > :after_id ORDER BY id ASC");
    $stmt_replies->execute(array(':tid' => $ticket_id, ':after_id' => $after_id));
    $raw_replies = $stmt_replies->fetchAll();

    $formatted_replies = array();
    foreach ($raw_replies as $r) {
        $is_support = ($r['sender_type'] === 'support');
        $msg_text = $r['message'];
        $diag_log = null;
        if (strpos($msg_text, '=== HARDWARE DIAGNOSTIC LOG ===') !== false) {
            $diag_log = format_diagnostic_log_text($msg_text);
        }

        $formatted_replies[] = array(
            'id' => intval($r['id']),
            'sender_type' => $r['sender_type'],
            'sender_name' => $r['sender_name'],
            'is_tech' => $is_support,
            'message' => $msg_text,
            'attachment_path' => !empty($r['attachment_path']) ? $r['attachment_path'] : null,
            'attachments' => parse_ticket_attachments($r['attachment_path']),
            'formatted_date' => format_date($r['created_at']),
            'diagnostic_log' => $diag_log
        );
    }

    echo json_encode(array(
        'success' => true,
        'ticket_status' => $ticket['status'],
        'status_badge_class' => get_status_badge_class($ticket['status']),
        'assigned_tech' => $ticket['assigned_tech'],
        'last_updated' => format_date($ticket['updated_at']),
        'replies' => $formatted_replies
    ));
    exit;
} catch (PDOException $e) {
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
    exit;
}
