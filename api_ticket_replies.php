<?php
// Client Portal Real-Time Ticket Chat API (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/hardware_data.php';
require_once __DIR__ . '/includes/ticket_chat_shared.php';

init_ticket_seen_typing_schema();

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

/**
 * May this client rewrite or take back this message? Their own messages only,
 * nothing already unsent, and only while the conversation is still open - the
 * composer locks on a Resolved or Closed ticket and so does this.
 *
 * @param array $reply         row from client_ticket_replies
 * @param array $ticket_row    row from client_support_tickets
 * @return bool
 */
function can_client_edit_reply($reply, $ticket_row) {
    if (!$reply || $reply['sender_type'] !== 'client') {
        return false;
    }
    if (!empty($reply['unsent_at'])) {
        return false;   // nothing left to change once a message is unsent
    }
    $status = isset($ticket_row['status']) ? $ticket_row['status'] : '';
    if (in_array($status, array('Resolved', 'Closed'))) {
        return false;
    }
    return true;
}

/**
 * One message of this ticket, or null.
 *
 * @param PDO $pdo
 * @param int $reply_id
 * @param int $ticket_id
 * @return array|null
 */
function get_ticket_reply_row($pdo, $reply_id, $ticket_id) {
    $stmt_one = $pdo->prepare("SELECT id, ticket_id, sender_type, sender_name, message, attachment_path, unsent_at
        FROM client_ticket_replies WHERE id = :rid AND ticket_id = :tid LIMIT 1");
    $stmt_one->execute(array(':rid' => intval($reply_id), ':tid' => intval($ticket_id)));
    $row = $stmt_one->fetch();
    return $row ? $row : null;
}

// -----------------------------------------------------------
// 0. POST: Edit one of this client's own messages
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_reply') {
    $reply_id = isset($_POST['reply_id']) ? intval($_POST['reply_id']) : 0;
    $new_message = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';

    if ($reply_id <= 0) {
        echo json_encode(array('success' => false, 'error' => 'No message selected.'));
        exit;
    }
    if ($new_message === '') {
        echo json_encode(array('success' => false, 'error' => 'The message cannot be left empty.'));
        exit;
    }

    try {
        $reply_row = get_ticket_reply_row($pdo, $reply_id, $ticket_id);
        if (!$reply_row) {
            echo json_encode(array('success' => false, 'error' => 'That message is no longer in this ticket.'));
            exit;
        }
        if (!can_client_edit_reply($reply_row, $ticket)) {
            echo json_encode(array('success' => false, 'error' => 'You can only edit your own messages while the ticket is open.'));
            exit;
        }

        $edited_at = date('Y-m-d H:i:s');
        $stmt_edit = $pdo->prepare("UPDATE client_ticket_replies SET message = :msg, edited_at = :eat WHERE id = :rid");
        $stmt_edit->execute(array(':msg' => $new_message, ':eat' => $edited_at, ':rid' => $reply_id));

        echo json_encode(array(
            'success' => true,
            'id' => $reply_id,
            'message' => $new_message,
            'edited' => true,
            'edited_at' => format_date($edited_at)
        ));
        exit;
    } catch (PDOException $e) {
        echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        exit;
    }
}

// -----------------------------------------------------------
// 0b. POST: Unsend one of this client's own messages
// The row stays so the thread keeps its shape, but the text and any files
// are cleared and the uploads are removed from disk.
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unsend_reply') {
    $reply_id = isset($_POST['reply_id']) ? intval($_POST['reply_id']) : 0;

    if ($reply_id <= 0) {
        echo json_encode(array('success' => false, 'error' => 'No message selected.'));
        exit;
    }

    try {
        $reply_row = get_ticket_reply_row($pdo, $reply_id, $ticket_id);
        if (!$reply_row) {
            echo json_encode(array('success' => false, 'error' => 'That message is no longer in this ticket.'));
            exit;
        }
        if (!can_client_edit_reply($reply_row, $ticket)) {
            echo json_encode(array('success' => false, 'error' => 'You can only unsend your own messages while the ticket is open.'));
            exit;
        }

        // Attachments go with the message - an unsent file should not stay
        // reachable by anyone who still has the link.
        foreach (parse_ticket_attachments($reply_row['attachment_path']) as $att) {
            $att_file = __DIR__ . '/' . ltrim($att, '/\\');
            if (file_exists($att_file) && is_file($att_file)) {
                @unlink($att_file);
            }
        }

        $unsent_at = date('Y-m-d H:i:s');
        $stmt_unsend = $pdo->prepare("UPDATE client_ticket_replies
            SET message = '', attachment_path = NULL, unsent_at = :uat
            WHERE id = :rid");
        $stmt_unsend->execute(array(':uat' => $unsent_at, ':rid' => $reply_id));

        echo json_encode(array(
            'success' => true,
            'id' => $reply_id,
            'unsent' => true,
            'unsent_at' => format_date($unsent_at)
        ));
        exit;
    } catch (PDOException $e) {
        echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        exit;
    }
}

// -----------------------------------------------------------
// 0c. POST: React / un-react to one message
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_reaction') {
    $reply_id = isset($_POST['reply_id']) ? intval($_POST['reply_id']) : 0;
    $reaction = isset($_POST['reaction']) ? trim($_POST['reaction']) : '';

    $result = toggle_ticket_reaction($pdo, $ticket_id, $reply_id, $reaction, 'client', $tradename);
    if (!$result['success']) {
        echo json_encode(array('success' => false, 'error' => $result['error']));
        exit;
    }

    $all_reactions = get_ticket_reply_reactions($pdo, $ticket_id, 'client', $tradename);
    echo json_encode(array(
        'success' => true,
        'reply_id' => $reply_id,
        'reaction' => $reaction,
        'active' => $result['active'],
        'reactions' => isset($all_reactions[$reply_id]) ? $all_reactions[$reply_id] : array()
    ));
    exit;
}

// -----------------------------------------------------------
// 1. POST: Send Reply via AJAX
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_reply') {
    if (isset($ticket['status']) && in_array($ticket['status'], array('Resolved', 'Closed'))) {
        echo json_encode(array('success' => false, 'error' => 'This ticket has been marked as ' . $ticket['status'] . '. Conversation is closed.'));
        exit;
    }

    $reply_message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $photo_attachments = upload_ticket_photos('attachments');

    if (empty($reply_message) && empty($photo_attachments)) {
        echo json_encode(array('success' => false, 'error' => 'Please enter a message or select photo(s).'));
        exit;
    }

    $now = date('Y-m-d H:i:s');
    try {
        $stmt_r = $pdo->prepare("INSERT INTO client_ticket_replies (ticket_id, sender_type, sender_name, message, attachment_path, created_at) VALUES (:tid, 'client', :sname, :msg, :att, :c_at)");
        $stmt_r->execute(array(
            ':tid' => $ticket_id,
            ':sname' => $tradename,
            ':msg' => $reply_message,
            ':att' => $photo_attachments ? $photo_attachments : null,
            ':c_at' => $now
        ));
        $new_reply_id = $pdo->lastInsertId();

        // Update ticket updated_at
        $stmt_u = $pdo->prepare("UPDATE client_support_tickets SET updated_at = :now WHERE id = :id");
        $stmt_u->execute(array(':now' => $now, ':id' => $ticket_id));

        // Sending a message implies this side has read up to here, and is no longer typing
        mark_ticket_seen($pdo, $ticket_id, 'client', $new_reply_id);
        clear_ticket_typing($pdo, $ticket_id, 'client');

        $parsed_attachments = parse_ticket_attachments($photo_attachments);

        echo json_encode(array(
            'success' => true,
            'reply' => array(
                'id' => intval($new_reply_id),
                'sender_type' => 'client',
                'sender_name' => $tradename,
                'is_client' => true,
                'message' => $reply_message,
                'attachment_path' => $photo_attachments ? $photo_attachments : null,
                'attachments' => $parsed_attachments,
                'formatted_date' => format_date($now),
                'diagnostic_log' => (strpos($reply_message, '=== HARDWARE DIAGNOSTIC LOG ===') !== false) ? format_diagnostic_log_text($reply_message) : null,
                // The sender draws this bubble straight from the response and the
                // poller never fetches it again, so its own controls are decided
                // here too - otherwise they only appear after a refresh.
                'can_edit' => can_client_edit_reply(array(
                    'sender_type' => 'client',
                    'sender_name' => $tradename,
                    'unsent_at' => null
                ), $ticket),
                'reactions' => array()
            )
        ));
        exit;
    } catch (PDOException $e) {
        echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        exit;
    }
}

// -----------------------------------------------------------
// 2. POST: Typing indicator ping
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'typing') {
    set_ticket_typing($pdo, $ticket_id, 'client', $tradename);
    echo json_encode(array('success' => true));
    exit;
}

// -----------------------------------------------------------
// 3. GET: Poll for New Replies
// -----------------------------------------------------------
$after_id = isset($_GET['after_id']) ? intval($_GET['after_id']) : 0;

try {
    $stmt_replies = $pdo->prepare("SELECT id, ticket_id, sender_type, sender_name, message, attachment_path, created_at, edited_at, unsent_at FROM client_ticket_replies WHERE ticket_id = :tid AND id > :after_id ORDER BY id ASC");
    $stmt_replies->execute(array(':tid' => $ticket_id, ':after_id' => $after_id));
    $raw_replies = $stmt_replies->fetchAll();

    // Viewing/polling the thread counts as having read everything currently in it
    $stmt_max = $pdo->prepare("SELECT MAX(id) FROM client_ticket_replies WHERE ticket_id = :tid");
    $stmt_max->execute(array(':tid' => $ticket_id));
    $max_reply_id_now = intval($stmt_max->fetchColumn());
    if ($max_reply_id_now > 0) {
        mark_ticket_seen($pdo, $ticket_id, 'client', $max_reply_id_now);
    }
    $support_is_typing = is_other_side_typing($pdo, $ticket_id, 'client');

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
            'attachments' => parse_ticket_attachments($r['attachment_path']),
            'formatted_date' => format_date($r['created_at']),
            'diagnostic_log' => $diag_log,
            'can_edit' => can_client_edit_reply($r, $ticket),
            'edited' => !empty($r['edited_at']),
            'edited_at' => !empty($r['edited_at']) ? format_date($r['edited_at']) : null,
            'unsent' => !empty($r['unsent_at'])
        );
    }

    // Reactions ride along for the whole thread on every poll, so a heart added
    // by support - or from the client's other device - reaches messages that
    // are already drawn.
    $reactions_map = get_ticket_reply_reactions($pdo, $ticket_id, 'client', $tradename);

    // Support can correct or unsend a message after sending it, so every changed
    // message in the thread rides along and the open chat updates in place.
    $edits_map = array();
    $stmt_edits = $pdo->prepare("SELECT id, message, edited_at, unsent_at FROM client_ticket_replies
        WHERE ticket_id = :tid AND (edited_at IS NOT NULL OR unsent_at IS NOT NULL)");
    $stmt_edits->execute(array(':tid' => $ticket_id));
    foreach ($stmt_edits->fetchAll() as $er) {
        $edits_map[strval($er['id'])] = array(
            'message' => $er['message'],
            'edited_at' => !empty($er['edited_at']) ? format_date($er['edited_at']) : null,
            'unsent' => !empty($er['unsent_at'])
        );
    }

    echo json_encode(array(
        'success' => true,
        'edits' => !empty($edits_map) ? $edits_map : new stdClass(),
        'reactions' => $reactions_map ? $reactions_map : new stdClass(),
        'ticket_status' => $ticket['status'],
        'status_badge_class' => get_status_badge_class($ticket['status']),
        'assigned_tech' => $ticket['assigned_tech'],
        'last_updated' => format_date($ticket['updated_at']),
        'replies' => $formatted_replies,
        'support_typing' => $support_is_typing
    ));
    exit;
} catch (PDOException $e) {
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
    exit;
}
