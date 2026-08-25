<?php
// Client Portal side of the read-receipt / typing-indicator support that
// backend/includes/ticket_chat_init.php provides for the technician console.
// Kept as a separate, self-contained copy (no shared require) because this
// file and the backend one are never loaded in the same request - each side
// only ever includes its own copy of config.php. (PHP 5.6 Compatible)

define('TICKET_TYPING_WINDOW_SECONDS', 6);

/**
 * Creates the seen-tracking columns + typing table if they don't exist yet.
 * Safe to call on every page load; the check runs once per session.
 */
function init_ticket_seen_typing_schema($force = false) {
    if (!$force && isset($_SESSION['ticket_seen_typing_schema_ready']) && $_SESSION['ticket_seen_typing_schema_ready']) {
        return true;
    }

    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $chk_seen_client = $pdo->query("SHOW COLUMNS FROM `client_support_tickets` LIKE 'client_last_seen_reply_id'");
        if ($chk_seen_client && $chk_seen_client->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `client_support_tickets`
                ADD `client_last_seen_reply_id` INT(11) NOT NULL DEFAULT 0,
                ADD `support_last_seen_reply_id` INT(11) NOT NULL DEFAULT 0");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `client_ticket_typing` (
            `ticket_id` INT(11) NOT NULL,
            `actor_type` VARCHAR(20) NOT NULL,
            `actor_name` VARCHAR(100) NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`ticket_id`, `actor_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

        $_SESSION['ticket_seen_typing_schema_ready'] = true;
        return true;
    } catch (PDOException $e) {
        error_log("Ticket seen/typing schema init error: " . $e->getMessage());
        return false;
    }
}

/**
 * Records that this side has read the thread up to (at least) $reply_id.
 * Never moves the marker backwards.
 *
 * @param PDO    $pdo
 * @param int    $ticket_id
 * @param string $actor_type 'support' or 'client'
 * @param int    $reply_id   Highest reply id visible to this viewer right now
 */
function mark_ticket_seen($pdo, $ticket_id, $actor_type, $reply_id) {
    $reply_id = intval($reply_id);
    if ($reply_id <= 0) {
        return;
    }
    $col = ($actor_type === 'support') ? 'support_last_seen_reply_id' : 'client_last_seen_reply_id';
    try {
        $stmt = $pdo->prepare("UPDATE client_support_tickets SET `$col` = :rid WHERE id = :tid AND `$col` < :rid2");
        $stmt->execute(array(':rid' => $reply_id, ':tid' => intval($ticket_id), ':rid2' => $reply_id));
    } catch (PDOException $e) {}
}

/**
 * @param PDO $pdo
 * @param int $ticket_id
 * @return array array('client' => int, 'support' => int)
 */
function get_ticket_seen_ids($pdo, $ticket_id) {
    try {
        $stmt = $pdo->prepare("SELECT client_last_seen_reply_id, support_last_seen_reply_id FROM client_support_tickets WHERE id = :tid LIMIT 1");
        $stmt->execute(array(':tid' => intval($ticket_id)));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        $row = null;
    }
    return array(
        'client' => $row ? intval($row['client_last_seen_reply_id']) : 0,
        'support' => $row ? intval($row['support_last_seen_reply_id']) : 0
    );
}

/**
 * Marks that $actor_type is actively typing in this ticket right now.
 */
function set_ticket_typing($pdo, $ticket_id, $actor_type, $actor_name) {
    try {
        $stmt = $pdo->prepare("INSERT INTO client_ticket_typing (ticket_id, actor_type, actor_name, updated_at)
            VALUES (:tid, :atype, :aname, :now)
            ON DUPLICATE KEY UPDATE actor_name = :aname2, updated_at = :now2");
        $stmt->execute(array(
            ':tid' => intval($ticket_id),
            ':atype' => $actor_type,
            ':aname' => $actor_name,
            ':now' => date('Y-m-d H:i:s'),
            ':aname2' => $actor_name,
            ':now2' => date('Y-m-d H:i:s')
        ));
    } catch (PDOException $e) {}
}

/**
 * Clears the typing flag for $actor_type, e.g. right after they send a message.
 */
function clear_ticket_typing($pdo, $ticket_id, $actor_type) {
    try {
        $stmt = $pdo->prepare("DELETE FROM client_ticket_typing WHERE ticket_id = :tid AND actor_type = :atype");
        $stmt->execute(array(':tid' => intval($ticket_id), ':atype' => $actor_type));
    } catch (PDOException $e) {}
}

/**
 * Whether the OTHER side of the conversation is currently typing.
 *
 * @param string $viewer_actor_type 'support' or 'client' - the side asking
 * @return bool
 */
function is_other_side_typing($pdo, $ticket_id, $viewer_actor_type) {
    $other_type = ($viewer_actor_type === 'support') ? 'client' : 'support';
    try {
        $stmt = $pdo->prepare("SELECT updated_at FROM client_ticket_typing WHERE ticket_id = :tid AND actor_type = :atype LIMIT 1");
        $stmt->execute(array(':tid' => intval($ticket_id), ':atype' => $other_type));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
    if (!$row) {
        return false;
    }
    return (time() - strtotime($row['updated_at'])) <= TICKET_TYPING_WINDOW_SECONDS;
}
