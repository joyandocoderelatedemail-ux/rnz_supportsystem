<?php
// API Endpoint for Checking New Support Tickets in Real-Time (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/presence_init.php';
require_once __DIR__ . '/../includes/ad_inquiry_init.php';

header('Content-Type: application/json');

if (!is_tech_logged_in()) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

// This poll runs on every backend page, so it keeps whoever left a tab open
// listed as online instead of idling out between page loads.
touch_user_presence(presence_current_page_label());

$pdo = get_db_connection();
init_ad_inquiry_tables($pdo);

// Ad inquiry threads are only visible to techs with client ads access -
// everyone else gets no inquiry fields at all.
$has_ads_access = user_has_page_access('packages');

/**
 * Shape one client inquiry message for the realtime poller.
 *
 * @param array $row message row joined to its inquiry
 * @return array
 */
function build_ad_inquiry_notif($row) {
    $snippet = trim(isset($row['message']) ? $row['message'] : '');
    if (strlen($snippet) > 120) {
        $snippet = substr($snippet, 0, 117) . '...';
    }
    return array(
        'id' => intval($row['id']),
        'inquiry_id' => intval($row['inquiry_id']),
        'inquiry_number' => $row['inquiry_number'],
        'tradename' => !empty($row['tradename']) ? $row['tradename'] : $row['sender_name'],
        'sender_name' => $row['sender_name'],
        'ad_title' => $row['ad_title'],
        'message' => $snippet,
        'created_at' => $row['created_at']
    );
}

try {
    // 1. Get latest ticket ID & count
    $stmt = $pdo->query("SELECT MAX(id) AS latest_id, COUNT(*) AS total_count FROM client_support_tickets");
    $row = $stmt->fetch();
    $latest_ticket_id = isset($row['latest_id']) ? intval($row['latest_id']) : 0;

    // 2. Get latest maintenance request ID & count
    $stmt_m = $pdo->query("SELECT MAX(id) AS latest_id, COUNT(*) AS total_count FROM client_maintenance_requests");
    $row_m = $stmt_m->fetch();
    $latest_maint_id = isset($row_m['latest_id']) ? intval($row_m['latest_id']) : 0;

    // Combined latest tracking ID
    $combined_latest_id = ($latest_ticket_id * 100000) + $latest_maint_id;

    // Pending counts
    $pending_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets WHERE status = 'Pending'")->fetchColumn());
    $pending_maint   = intval($pdo->query("SELECT COUNT(*) FROM client_maintenance_requests WHERE status = 'Pending'")->fetchColumn());
    $pending_count   = $pending_tickets + $pending_maint;

    // Get latest notification item
    $latest_item = null;
    $latest_type = 'ticket';

    $latest_maint_item = null;
    if ($latest_maint_id > 0) {
        $stmt_m_item = $pdo->prepare("SELECT id, request_number, tradename, preferred_date, preferred_time, units_count, created_at FROM client_maintenance_requests WHERE id = :id LIMIT 1");
        $stmt_m_item->execute(array(':id' => $latest_maint_id));
        $latest_maint_item = $stmt_m_item->fetch();
    }

    $latest_ticket_item = null;
    if ($latest_ticket_id > 0) {
        $stmt_t_item = $pdo->prepare("SELECT id, ticket_number, tradename, subject, category, priority, created_at FROM client_support_tickets WHERE id = :id LIMIT 1");
        $stmt_t_item->execute(array(':id' => $latest_ticket_id));
        $latest_ticket_item = $stmt_t_item->fetch();
    }

    if ($latest_maint_item && (!$latest_ticket_item || strtotime($latest_maint_item['created_at']) > strtotime($latest_ticket_item['created_at']))) {
        $latest_type = 'maintenance';
        $latest_item = array(
            'id' => $latest_maint_item['id'],
            'ticket_number' => $latest_maint_item['request_number'],
            'tradename' => $latest_maint_item['tradename'],
            'subject' => 'POS Maintenance (' . $latest_maint_item['units_count'] . ' units)',
            'category' => 'Maintenance',
            'priority' => 'POS Maintenance',
            'created_at' => $latest_maint_item['created_at']
        );
    } else {
        $latest_item = $latest_ticket_item;
    }

    // Newest FOLLOW-UP message a client has written on any ticket, so the
    // poller can sound the alarm for a reply the same way it does for a new
    // ticket. A ticket's opening message is also stored as a client reply
    // row - excluding it here stops a brand new ticket from firing both the
    // "new ticket" toast and a second "new client message" toast for the
    // exact same event.
    $latest_reply = null;
    $stmt_reply = $pdo->query("SELECT r.id, r.ticket_id, r.sender_name, r.message, r.attachment_path, r.created_at,
            t.ticket_number, t.subject, t.tradename
        FROM client_ticket_replies r
        INNER JOIN client_support_tickets t ON t.id = r.ticket_id
        WHERE r.sender_type = 'client'
          AND r.id <> (SELECT MIN(id) FROM client_ticket_replies WHERE ticket_id = r.ticket_id)
        ORDER BY r.id DESC
        LIMIT 1");
    $reply_row = $stmt_reply ? $stmt_reply->fetch() : null;

    if ($reply_row) {
        $snippet = trim($reply_row['message']);
        if ($snippet === '' && !empty($reply_row['attachment_path'])) {
            $snippet = 'Sent a photo attachment.';
        }
        if (strlen($snippet) > 120) {
            $snippet = substr($snippet, 0, 117) . '...';
        }
        $latest_reply = array(
            'id' => intval($reply_row['id']),
            'ticket_id' => intval($reply_row['ticket_id']),
            'ticket_number' => $reply_row['ticket_number'],
            'tradename' => !empty($reply_row['tradename']) ? $reply_row['tradename'] : $reply_row['sender_name'],
            'subject' => $reply_row['subject'],
            'message' => $snippet,
            'created_at' => $reply_row['created_at']
        );
    }

    // Newest FIRST client message per ad thread: that question is the "new
    // inquiry" event (a thread is created empty when the client taps Inquire,
    // so thread creation alone carries no question yet). Follow-up client
    // messages are tracked separately below, so one message never fires both.
    $latest_inquiry_event = null;
    $latest_inquiry_reply = null;
    if ($has_ads_access) {
        $stmt_ie = $pdo->query("SELECT m.id, m.inquiry_id, m.sender_name, m.message, m.created_at,
                i.inquiry_number, i.tradename, i.ad_title
            FROM client_ad_inquiry_messages m
            INNER JOIN client_ad_inquiries i ON i.id = m.inquiry_id
            WHERE m.sender_type = 'client'
              AND m.id = (SELECT MIN(id) FROM client_ad_inquiry_messages WHERE inquiry_id = m.inquiry_id)
            ORDER BY m.id DESC
            LIMIT 1");
        $event_row = $stmt_ie ? $stmt_ie->fetch() : null;
        if ($event_row) {
            $latest_inquiry_event = build_ad_inquiry_notif($event_row);
        }

        $stmt_ir = $pdo->query("SELECT m.id, m.inquiry_id, m.sender_name, m.message, m.created_at,
                i.inquiry_number, i.tradename, i.ad_title
            FROM client_ad_inquiry_messages m
            INNER JOIN client_ad_inquiries i ON i.id = m.inquiry_id
            WHERE m.sender_type = 'client'
              AND m.id <> (SELECT MIN(id) FROM client_ad_inquiry_messages WHERE inquiry_id = m.inquiry_id)
            ORDER BY m.id DESC
            LIMIT 1");
        $reply_ir_row = $stmt_ir ? $stmt_ir->fetch() : null;
        if ($reply_ir_row) {
            $latest_inquiry_reply = build_ad_inquiry_notif($reply_ir_row);
        }
    }

    echo json_encode(array(
        'success' => true,
        'latest_id' => $combined_latest_id,
        'pending_count' => $pending_count,
        'latest_type' => $latest_type,
        'latest_ticket' => $latest_item,
        'latest_client_reply_id' => $latest_reply ? $latest_reply['id'] : 0,
        'latest_client_reply' => $latest_reply,
        'latest_inquiry_event_id' => $latest_inquiry_event ? $latest_inquiry_event['id'] : 0,
        'latest_inquiry_event' => $latest_inquiry_event,
        'latest_inquiry_reply_id' => $latest_inquiry_reply ? $latest_inquiry_reply['id'] : 0,
        'latest_inquiry_reply' => $latest_inquiry_reply
    ));
} catch (PDOException $e) {
    echo json_encode(array('error' => $e->getMessage()));
}
?>
