<?php
// Support Center Technician Real-Time Ticket Chat API (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/hardware_data.php';
require_once __DIR__ . '/includes/ticket_chat_init.php';

init_ticket_chat_tables();

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

$stmt_check = $pdo->prepare("SELECT id, ticket_number, status, priority, category, assigned_tech, updated_at, in_progress_by, in_progress_at FROM client_support_tickets WHERE id = :id LIMIT 1");
$stmt_check->execute(array(':id' => $ticket_id));
$ticket = $stmt_check->fetch();

if (!$ticket) {
    echo json_encode(array('success' => false, 'error' => 'Ticket not found'));
    exit;
}

/**
 * May the signed-in technician rewrite this message? Support messages only,
 * and only the author - a Super Admin can correct anyone on the support side.
 *
 * @param array  $reply     row from client_ticket_replies
 * @param string $tech_name signed-in technician full name
 * @return bool
 */
function can_edit_ticket_reply($reply, $tech_name) {
    if (!$reply || $reply['sender_type'] !== 'support') {
        return false;
    }
    if (!empty($reply['unsent_at'])) {
        return false;   // nothing left to change once a message is unsent
    }
    if (get_logged_tech_access_tier() === 1) {
        return false;   // Level 1 accounts are view only
    }
    if (is_super_admin()) {
        return true;
    }
    return (strtolower(trim($reply['sender_name'])) === strtolower(trim($tech_name)));
}

// -----------------------------------------------------------
// 0. POST: Edit an existing support message
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_reply') {
    $reply_id = isset($_POST['reply_id']) ? intval($_POST['reply_id']) : 0;
    $new_message = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';

    $perm_check = check_tech_action_permission($action_code);
    if (!$perm_check['allowed']) {
        echo json_encode(array(
            'success' => false,
            'error' => $perm_check['message'],
            'needs_code' => (get_logged_tech_access_tier() === 2)
        ));
        exit;
    }

    if ($reply_id <= 0) {
        echo json_encode(array('success' => false, 'error' => 'No message selected.'));
        exit;
    }
    if ($new_message === '') {
        echo json_encode(array('success' => false, 'error' => 'The message cannot be left empty.'));
        exit;
    }

    try {
        $stmt_one = $pdo->prepare("SELECT id, ticket_id, sender_type, sender_name, message, attachment_path, unsent_at
            FROM client_ticket_replies WHERE id = :rid AND ticket_id = :tid LIMIT 1");
        $stmt_one->execute(array(':rid' => $reply_id, ':tid' => $ticket_id));
        $reply_row = $stmt_one->fetch();

        if (!$reply_row) {
            echo json_encode(array('success' => false, 'error' => 'That message is no longer in this ticket.'));
            exit;
        }
        if (!can_edit_ticket_reply($reply_row, $tech_name)) {
            echo json_encode(array('success' => false, 'error' => 'You can only edit your own support messages.'));
            exit;
        }

        $edited_at = date('Y-m-d H:i:s');
        $stmt_edit = $pdo->prepare("UPDATE client_ticket_replies SET message = :msg, edited_at = :eat WHERE id = :rid");
        $stmt_edit->execute(array(':msg' => $new_message, ':eat' => $edited_at, ':rid' => $reply_id));

        echo json_encode(array(
            'success' => true,
            'id' => $reply_id,
            'message' => $new_message,
            'reply_snippet' => build_reply_snippet($new_message, $reply_row['attachment_path']),
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
// 0b. POST: Unsend a support message
// The row stays so replies quoting it still line up, but the text and any
// photos are cleared and the files are removed from disk.
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unsend_reply') {
    $reply_id = isset($_POST['reply_id']) ? intval($_POST['reply_id']) : 0;
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';

    $perm_check = check_tech_action_permission($action_code);
    if (!$perm_check['allowed']) {
        echo json_encode(array(
            'success' => false,
            'error' => $perm_check['message'],
            'needs_code' => (get_logged_tech_access_tier() === 2)
        ));
        exit;
    }

    if ($reply_id <= 0) {
        echo json_encode(array('success' => false, 'error' => 'No message selected.'));
        exit;
    }

    try {
        $stmt_one = $pdo->prepare("SELECT id, ticket_id, sender_type, sender_name, message, attachment_path, unsent_at
            FROM client_ticket_replies WHERE id = :rid AND ticket_id = :tid LIMIT 1");
        $stmt_one->execute(array(':rid' => $reply_id, ':tid' => $ticket_id));
        $reply_row = $stmt_one->fetch();

        if (!$reply_row) {
            echo json_encode(array('success' => false, 'error' => 'That message is no longer in this ticket.'));
            exit;
        }
        if (!can_edit_ticket_reply($reply_row, $tech_name)) {
            echo json_encode(array('success' => false, 'error' => 'You can only unsend your own support messages.'));
            exit;
        }

        // Photos go with the message - an unsent attachment should not stay
        // reachable by anyone who still has the link.
        foreach (parse_ticket_attachments($reply_row['attachment_path']) as $att) {
            $att_file = __DIR__ . '/../' . ltrim($att, '/\\');
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

    // Optional: this reply answers one specific message in the thread
    $reply_to_id = isset($_POST['reply_to_id']) ? intval($_POST['reply_to_id']) : 0;
    $reply_to_info = get_reply_parent_info($pdo, $reply_to_id, $ticket_id);
    if (!$reply_to_info) {
        $reply_to_id = 0; // Unknown or belongs to another ticket - send as a normal reply
    }

    $now = date('Y-m-d H:i:s');
    try {
        $stmt_rep = $pdo->prepare("INSERT INTO client_ticket_replies (ticket_id, reply_to_id, sender_type, sender_name, message, attachment_path, created_at) VALUES (:tid, :rto, 'support', :sname, :msg, :att, :c_at)");
        $stmt_rep->execute(array(
            ':tid' => $ticket_id,
            ':rto' => ($reply_to_id > 0) ? $reply_to_id : null,
            ':sname' => $tech_name,
            ':msg' => $reply_msg,
            ':att' => $photo_attachments ? $photo_attachments : null,
            ':c_at' => $now
        ));
        $new_reply_id = $pdo->lastInsertId();

        // Auto update status to In Progress if currently Pending. Replying is
        // how most tickets get picked up, so the replier is credited with it.
        $pdo->prepare("UPDATE client_support_tickets
            SET status = 'In Progress', updated_at = :now, in_progress_by = :by, in_progress_at = :at
            WHERE id = :tid AND status = 'Pending'")
            ->execute(array(':now' => $now, ':by' => $tech_name, ':at' => $now, ':tid' => $ticket_id));

        // Sending a message implies this side has read up to here, and is no longer typing
        mark_ticket_seen($pdo, $ticket_id, 'support', $new_reply_id);
        clear_ticket_typing($pdo, $ticket_id, 'support');

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
                'reply_snippet' => build_reply_snippet($reply_msg, $photo_attachments),
                'reply_to' => $reply_to_info,
                'reactions' => array(),
                // The sender renders this bubble straight from the response and
                // the poller never fetches it again, so the Edit / Unsend row
                // has to be decided here too - without it those buttons only
                // appeared after a page refresh.
                'can_edit' => can_edit_ticket_reply(array(
                    'sender_type' => 'support',
                    'sender_name' => $tech_name,
                    'unsent_at' => null
                ), $tech_name),
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
// 2. POST: React / un-react to one message
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_reaction') {
    $reply_id = isset($_POST['reply_id']) ? intval($_POST['reply_id']) : 0;
    $reaction = isset($_POST['reaction']) ? trim($_POST['reaction']) : '';

    $result = toggle_ticket_reaction($pdo, $ticket_id, $reply_id, $reaction, 'support', $tech_name);
    if (!$result['success']) {
        echo json_encode(array('success' => false, 'error' => $result['error']));
        exit;
    }

    $all_reactions = get_ticket_reply_reactions($pdo, $ticket_id, 'support', $tech_name);
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
// 3. POST: Typing indicator ping
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'typing') {
    set_ticket_typing($pdo, $ticket_id, 'support', $tech_name);
    echo json_encode(array('success' => true));
    exit;
}

// -----------------------------------------------------------
// 4. POST: Change the ticket status from the chat pop-up
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    // Same tier rules as every other record change: level 1 cannot edit,
    // level 2 must confirm with a security access code.
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
    $perm_check = check_tech_action_permission($action_code);
    if (!$perm_check['allowed']) {
        echo json_encode(array(
            'success' => false,
            'error' => $perm_check['message'],
            'needs_code' => (get_logged_tech_access_tier() === 2)
        ));
        exit;
    }

    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $allowed_statuses = array('Pending', 'In Progress', 'Resolved', 'Closed');
    if (!in_array($new_status, $allowed_statuses, true)) {
        echo json_encode(array('success' => false, 'error' => 'Unknown ticket status.'));
        exit;
    }

    $was_already_closed = (isset($ticket['status']) && $ticket['status'] === 'Closed');
    $now = date('Y-m-d H:i:s');

    try {
        if ($new_status === 'In Progress') {
            // Record who picked the ticket up, so the chat can name them
            $stmt_st = $pdo->prepare("UPDATE client_support_tickets
                SET status = :status, updated_at = :now, in_progress_by = :by, in_progress_at = :at
                WHERE id = :tid");
            $stmt_st->execute(array(
                ':status' => $new_status,
                ':now' => $now,
                ':by' => $tech_name,
                ':at' => $now,
                ':tid' => $ticket_id
            ));
        } else {
            $stmt_st = $pdo->prepare("UPDATE client_support_tickets SET status = :status, updated_at = :now WHERE id = :tid");
            $stmt_st->execute(array(
                ':status' => $new_status,
                ':now' => $now,
                ':tid' => $ticket_id
            ));
        }

        // Leave a closing note in the thread the first time this ticket is closed,
        // so the client sees why the conversation ended.
        if ($new_status === 'Closed' && !$was_already_closed) {
            $closing_msg = "Since we have not received a response from the client, we will be closing this ticket.";
            $stmt_close = $pdo->prepare("INSERT INTO client_ticket_replies
                (ticket_id, sender_type, sender_name, message, created_at)
                VALUES (:tid, 'support', :sname, :msg, :c_at)");
            $stmt_close->execute(array(
                ':tid' => $ticket_id,
                ':sname' => $tech_name,
                ':msg' => $closing_msg,
                ':c_at' => $now
            ));
            mark_ticket_seen($pdo, $ticket_id, 'support', intval($pdo->lastInsertId()));
        }
    } catch (PDOException $e) {
        echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        exit;
    }

    echo json_encode(array(
        'success' => true,
        'status' => $new_status,
        'status_badge_class' => get_status_badge_class($new_status),
        'in_progress_by' => ($new_status === 'In Progress') ? $tech_name : null,
        'in_progress_at' => ($new_status === 'In Progress') ? format_date($now) : null
    ));
    exit;
}

// -----------------------------------------------------------
// 5. GET: Poll for New Replies
// -----------------------------------------------------------
$after_id = isset($_GET['after_id']) ? intval($_GET['after_id']) : 0;

try {
    $stmt_replies = $pdo->prepare("SELECT id, ticket_id, reply_to_id, sender_type, sender_name, message, attachment_path, created_at, edited_at, unsent_at FROM client_ticket_replies WHERE ticket_id = :tid AND id > :after_id ORDER BY id ASC");
    $stmt_replies->execute(array(':tid' => $ticket_id, ':after_id' => $after_id));
    $raw_replies = $stmt_replies->fetchAll();

    // Viewing/polling the thread counts as having read everything currently in it
    $stmt_max = $pdo->prepare("SELECT MAX(id) FROM client_ticket_replies WHERE ticket_id = :tid");
    $stmt_max->execute(array(':tid' => $ticket_id));
    $max_reply_id_now = intval($stmt_max->fetchColumn());
    if ($max_reply_id_now > 0) {
        mark_ticket_seen($pdo, $ticket_id, 'support', $max_reply_id_now);
    }
    $seen_ids = get_ticket_seen_ids($pdo, $ticket_id);
    $client_is_typing = is_other_side_typing($pdo, $ticket_id, 'support');

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
            'reply_snippet' => build_reply_snippet($msg_text, $r['attachment_path']),
            'reply_to' => get_reply_parent_info($pdo, isset($r['reply_to_id']) ? $r['reply_to_id'] : 0, $ticket_id),
            'diagnostic_log' => $diag_log,
            'can_edit' => can_edit_ticket_reply($r, $tech_name),
            'edited' => !empty($r['edited_at']),
            'edited_at' => !empty($r['edited_at']) ? format_date($r['edited_at']) : null,
            'unsent' => !empty($r['unsent_at'])
        );
    }

    // Messages already on screen may have been rewritten or unsent since they
    // were drawn, so every changed message in the thread rides along on each poll.
    $edits_map = array();
    $stmt_edits = $pdo->prepare("SELECT id, message, attachment_path, edited_at, unsent_at FROM client_ticket_replies
        WHERE ticket_id = :tid AND (edited_at IS NOT NULL OR unsent_at IS NOT NULL)");
    $stmt_edits->execute(array(':tid' => $ticket_id));
    foreach ($stmt_edits->fetchAll() as $er) {
        $edits_map[strval($er['id'])] = array(
            'message' => $er['message'],
            'reply_snippet' => build_reply_snippet($er['message'], $er['attachment_path']),
            'edited_at' => !empty($er['edited_at']) ? format_date($er['edited_at']) : null,
            'unsent' => !empty($er['unsent_at'])
        );
    }

    // Reactions are sent for the whole thread on every poll, so a reaction added
    // by someone else shows up on messages that are already on screen.
    $reactions_map = get_ticket_reply_reactions($pdo, $ticket_id, 'support', $tech_name);

    echo json_encode(array(
        'success' => true,
        'ticket_status' => $ticket['status'],
        'status_badge_class' => get_status_badge_class($ticket['status']),
        'assigned_tech' => $ticket['assigned_tech'],
        'last_updated' => format_date($ticket['updated_at']),
        'in_progress_by' => !empty($ticket['in_progress_by']) ? $ticket['in_progress_by'] : null,
        'in_progress_at' => !empty($ticket['in_progress_at']) ? format_date($ticket['in_progress_at']) : null,
        'replies' => $formatted_replies,
        'edits' => !empty($edits_map) ? $edits_map : new stdClass(),
        'reactions' => $reactions_map ? $reactions_map : new stdClass(),
        'client_seen_id' => $seen_ids['client'],
        'client_typing' => $client_is_typing
    ));
    exit;
} catch (PDOException $e) {
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
    exit;
}
