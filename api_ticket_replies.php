<?php
// Client Portal Real-Time Ticket Chat API (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/hardware_data.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_logged_in()) {
    echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
    exit;
}

$client = get_logged_client();
$accountnum = isset($client['accountnum']) ? $client['accountnum'] : '';
$tradename = isset($client['tradename']) ? $client['tradename'] : 'Client';

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

// Verify ownership
$stmt_check = $pdo->prepare("SELECT id, ticket_number, status, priority, category, assigned_tech, updated_at FROM client_support_tickets WHERE id = :id AND accountnum = :acct LIMIT 1");
$stmt_check->execute(array(':id' => $ticket_id, ':acct' => $accountnum));
$ticket = $stmt_check->fetch();

if (!$ticket) {
    echo json_encode(array('success' => false, 'error' => 'Ticket not found or permission denied'));
    exit;
}

// -----------------------------------------------------------
// 1. POST: Send Reply via AJAX
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_reply') {
    $reply_message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $photo_attachment = upload_ticket_photo('attachment');

    if (empty($reply_message) && empty($photo_attachment)) {
        echo json_encode(array('success' => false, 'error' => 'Please enter a message or select a photo.'));
        exit;
    }

    $now = date('Y-m-d H:i:s');
    try {
        $stmt_r = $pdo->prepare("INSERT INTO client_ticket_replies (ticket_id, sender_type, sender_name, message, attachment_path, created_at) VALUES (:tid, 'client', :sname, :msg, :att, :c_at)");
        $stmt_r->execute(array(
            ':tid' => $ticket_id,
            ':sname' => $tradename,
            ':msg' => $reply_message,
            ':att' => $photo_attachment ? $photo_attachment : null,
            ':c_at' => $now
        ));
        $new_reply_id = $pdo->lastInsertId();

        // Update ticket updated_at
        $stmt_u = $pdo->prepare("UPDATE client_support_tickets SET updated_at = :now WHERE id = :id");
        $stmt_u->execute(array(':now' => $now, ':id' => $ticket_id));

        echo json_encode(array(
            'success' => true,
            'reply' => array(
                'id' => intval($new_reply_id),
                'sender_type' => 'client',
                'sender_name' => $tradename,
                'is_client' => true,
                'message' => $reply_message,
                'attachment_path' => $photo_attachment ? $photo_attachment : null,
                'formatted_date' => format_date($now),
                'diagnostic_log' => (strpos($reply_message, '=== HARDWARE DIAGNOSTIC LOG ===') !== false) ? format_diagnostic_log_text($reply_message) : null
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
        $is_client = ($r['sender_type'] === 'client');
        $msg_text = $r['message'];
        $diag_log = null;
        if (strpos($msg_text, '=== HARDWARE DIAGNOSTIC LOG ===') !== false) {
            $diag_log = format_diagnostic_log_text($msg_text);
        }

        $formatted_replies[] = array(
            'id' => intval($r['id']),
            'sender_type' => $r['sender_type'],
            'sender_name' => $r['sender_name'],
            'is_client' => $is_client,
            'message' => $msg_text,
            'attachment_path' => !empty($r['attachment_path']) ? $r['attachment_path'] : null,
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
