<?php
// Ticket Chat Extras: threaded replies + message reactions (PHP 5.6 Compatible)
require_once __DIR__ . '/config.php';

/**
 * Create/patch the tables the ticket chat needs for replying to a specific
 * message and for reacting to messages. Safe to call on every page load.
 *
 * @return bool
 */
function init_ticket_chat_tables($force = false) {
    // The chat API is polled every few seconds, so the schema check runs once
    // per session instead of on every request.
    if (!$force && isset($_SESSION['ticket_chat_schema_ready_v2']) && $_SESSION['ticket_chat_schema_ready_v2']) {
        return true;
    }

    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        // Which message a reply is answering (NULL = plain reply to the thread)
        $chk_col = $pdo->query("SHOW COLUMNS FROM `client_ticket_replies` LIKE 'reply_to_id'");
        if ($chk_col && $chk_col->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `client_ticket_replies`
                ADD `reply_to_id` INT(11) NULL DEFAULT NULL AFTER `ticket_id`,
                ADD KEY `reply_to_id` (`reply_to_id`)");
        }

        // One row per person per reaction per message. The reaction is stored as
        // a short ASCII key (not the emoji) because the DB connection runs on
        // 3-byte utf8, which cannot hold emoji characters.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `client_ticket_reactions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `ticket_id` INT(11) NOT NULL,
            `reply_id` INT(11) NOT NULL,
            `reaction` VARCHAR(20) NOT NULL,
            `reactor_type` VARCHAR(20) NOT NULL,
            `reactor_name` VARCHAR(100) NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_reaction` (`reply_id`, `reactor_type`, `reactor_name`, `reaction`),
            KEY `ticket_id` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

        // How far each side has read the thread, so the other side can show "Seen"
        $chk_seen_client = $pdo->query("SHOW COLUMNS FROM `client_support_tickets` LIKE 'client_last_seen_reply_id'");
        if ($chk_seen_client && $chk_seen_client->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `client_support_tickets`
                ADD `client_last_seen_reply_id` INT(11) NOT NULL DEFAULT 0,
                ADD `support_last_seen_reply_id` INT(11) NOT NULL DEFAULT 0");
        }

        // Who picked the ticket up - the technician who moved it to In Progress,
        // whether by changing the status or by being first to reply
        $chk_wip = $pdo->query("SHOW COLUMNS FROM `client_support_tickets` LIKE 'in_progress_by'");
        if ($chk_wip && $chk_wip->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `client_support_tickets`
                ADD `in_progress_by` VARCHAR(100) NULL DEFAULT NULL,
                ADD `in_progress_at` DATETIME NULL DEFAULT NULL");
        }

        // One live row per ticket per side, refreshed while someone is typing
        $pdo->exec("CREATE TABLE IF NOT EXISTS `client_ticket_typing` (
            `ticket_id` INT(11) NOT NULL,
            `actor_type` VARCHAR(20) NOT NULL,
            `actor_name` VARCHAR(100) NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`ticket_id`, `actor_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

        $_SESSION['ticket_chat_schema_ready_v2'] = true;
        return true;
    } catch (PDOException $e) {
        error_log("Ticket chat init error: " . $e->getMessage());
        return false;
    }
}

/**
 * The reactions a message can carry. Keys are what the database stores.
 * Only the heart is offered - any other key is rejected and older rows with
 * a different key are simply not rendered.
 *
 * @return array key => array('emoji' => string, 'label' => string)
 */
function get_ticket_reaction_catalog() {
    return array(
        'heart' => array('emoji' => "\xE2\x9D\xA4\xEF\xB8\x8F", 'label' => 'Loved this message')
    );
}

/**
 * @param string $reaction
 * @return bool
 */
function is_valid_ticket_reaction($reaction) {
    $catalog = get_ticket_reaction_catalog();
    return isset($catalog[$reaction]);
}

/**
 * Short one line preview of a message, used in the "replying to" quote block.
 *
 * @param string $message
 * @param string $attachment_path
 * @param int    $limit
 * @return string
 */
function build_reply_snippet($message, $attachment_path = '', $limit = 120) {
    $text = trim((string)$message);

    if (strpos($text, '=== HARDWARE DIAGNOSTIC LOG ===') !== false) {
        return 'Hardware diagnostic log';
    }

    $text = preg_replace('/\s+/', ' ', $text);
    if ($text === '') {
        $atts = function_exists('parse_ticket_attachments') ? parse_ticket_attachments($attachment_path) : array();
        $count = is_array($atts) ? count($atts) : 0;
        if ($count > 0) {
            return $count === 1 ? 'Photo attachment' : $count . ' photo attachments';
        }
        return 'Message';
    }

    if (strlen($text) > $limit) {
        $text = substr($text, 0, $limit);
        // Do not cut a multi byte character in half
        while (strlen($text) > 0 && (ord(substr($text, -1)) & 0xC0) === 0x80) {
            $text = substr($text, 0, -1);
        }
        $text = rtrim($text) . '...';
    }
    return $text;
}

/**
 * Details of the message a reply is quoting, ready for rendering.
 *
 * @param PDO $pdo
 * @param int $reply_to_id
 * @param int $ticket_id Restricts the lookup to the same ticket
 * @return array|null array('id','sender_name','sender_type','is_tech','snippet')
 */
function get_reply_parent_info($pdo, $reply_to_id, $ticket_id) {
    $reply_to_id = intval($reply_to_id);
    if ($reply_to_id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, sender_type, sender_name, message, attachment_path
            FROM client_ticket_replies WHERE id = :rid AND ticket_id = :tid LIMIT 1");
        $stmt->execute(array(':rid' => $reply_to_id, ':tid' => intval($ticket_id)));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    return array(
        'id' => intval($row['id']),
        'sender_type' => $row['sender_type'],
        'sender_name' => $row['sender_name'],
        'is_tech' => ($row['sender_type'] === 'support'),
        'snippet' => build_reply_snippet($row['message'], $row['attachment_path'])
    );
}

/**
 * All reactions on a ticket, grouped per message and per reaction key.
 *
 * @param PDO    $pdo
 * @param int    $ticket_id
 * @param string $actor_type 'support' or 'client' - marks the viewer's own reactions
 * @param string $actor_name
 * @return array reply_id => list of array('reaction','emoji','label','count','mine','who')
 */
function get_ticket_reply_reactions($pdo, $ticket_id, $actor_type = '', $actor_name = '') {
    $out = array();
    try {
        $stmt = $pdo->prepare("SELECT reply_id, reaction, reactor_type, reactor_name
            FROM client_ticket_reactions WHERE ticket_id = :tid ORDER BY id ASC");
        $stmt->execute(array(':tid' => intval($ticket_id)));
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return $out; // Table not ready yet - the chat still works without reactions
    }

    $catalog = get_ticket_reaction_catalog();
    $grouped = array();

    foreach ($rows as $row) {
        $rid = intval($row['reply_id']);
        $key = $row['reaction'];
        if (!isset($catalog[$key])) {
            continue;
        }
        if (!isset($grouped[$rid])) {
            $grouped[$rid] = array();
        }
        if (!isset($grouped[$rid][$key])) {
            $grouped[$rid][$key] = array(
                'reaction' => $key,
                'emoji' => $catalog[$key]['emoji'],
                'label' => $catalog[$key]['label'],
                'count' => 0,
                'mine' => false,
                'who' => array()
            );
        }
        $grouped[$rid][$key]['count']++;
        $grouped[$rid][$key]['who'][] = $row['reactor_name'] . (($row['reactor_type'] === 'support') ? ' (Support)' : ' (Client)');
        if ($actor_name !== '' && $row['reactor_type'] === $actor_type && $row['reactor_name'] === $actor_name) {
            $grouped[$rid][$key]['mine'] = true;
        }
    }

    // Flatten to a list per message, keeping the catalog order stable
    foreach ($grouped as $rid => $by_key) {
        $list = array();
        foreach ($catalog as $key => $meta) {
            if (isset($by_key[$key])) {
                $item = $by_key[$key];
                $item['who'] = implode(', ', $item['who']);
                $list[] = $item;
            }
        }
        if (!empty($list)) {
            $out[$rid] = $list;
        }
    }
    return $out;
}

/**
 * Add the reaction if this person has not used it on this message yet,
 * remove it if they have.
 *
 * @return array array('success' => bool, 'active' => bool, 'error' => string)
 */
function toggle_ticket_reaction($pdo, $ticket_id, $reply_id, $reaction, $actor_type, $actor_name) {
    $ticket_id = intval($ticket_id);
    $reply_id = intval($reply_id);

    if ($reply_id <= 0 || !is_valid_ticket_reaction($reaction)) {
        return array('success' => false, 'active' => false, 'error' => 'Invalid message or reaction.');
    }
    if (trim($actor_name) === '') {
        return array('success' => false, 'active' => false, 'error' => 'Unable to identify who is reacting.');
    }

    try {
        // The message must belong to this ticket
        $stmt_chk = $pdo->prepare("SELECT id FROM client_ticket_replies WHERE id = :rid AND ticket_id = :tid LIMIT 1");
        $stmt_chk->execute(array(':rid' => $reply_id, ':tid' => $ticket_id));
        if (!$stmt_chk->fetch()) {
            return array('success' => false, 'active' => false, 'error' => 'Message not found in this ticket.');
        }

        $stmt_ex = $pdo->prepare("SELECT id FROM client_ticket_reactions
            WHERE reply_id = :rid AND reaction = :rx AND reactor_type = :rtype AND reactor_name = :rname LIMIT 1");
        $stmt_ex->execute(array(
            ':rid' => $reply_id,
            ':rx' => $reaction,
            ':rtype' => $actor_type,
            ':rname' => $actor_name
        ));
        $existing = $stmt_ex->fetch();

        if ($existing) {
            $stmt_del = $pdo->prepare("DELETE FROM client_ticket_reactions WHERE id = :id");
            $stmt_del->execute(array(':id' => intval($existing['id'])));
            return array('success' => true, 'active' => false, 'error' => '');
        }

        $stmt_ins = $pdo->prepare("INSERT INTO client_ticket_reactions
            (ticket_id, reply_id, reaction, reactor_type, reactor_name, created_at)
            VALUES (:tid, :rid, :rx, :rtype, :rname, :now)");
        $stmt_ins->execute(array(
            ':tid' => $ticket_id,
            ':rid' => $reply_id,
            ':rx' => $reaction,
            ':rtype' => $actor_type,
            ':rname' => $actor_name,
            ':now' => date('Y-m-d H:i:s')
        ));
        return array('success' => true, 'active' => true, 'error' => '');
    } catch (PDOException $e) {
        return array('success' => false, 'active' => false, 'error' => $e->getMessage());
    }
}

// How long a "typing" ping stays valid before it's considered stale
define('TICKET_TYPING_WINDOW_SECONDS', 6);

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
 * Client messages the support side has not read yet, per ticket. Opening a
 * ticket chat marks the whole thread read, so a count here means nobody on the
 * support side has looked at that conversation since the client last wrote.
 *
 * @param PDO   $pdo
 * @param array $ticket_ids limit to these tickets; empty means every ticket
 * @return array ticket_id => unread count (tickets with none are absent)
 */
function get_support_unread_counts($pdo, $ticket_ids = array()) {
    if (!$pdo) {
        return array();
    }

    $params = array();
    $filter = '';
    if (!empty($ticket_ids)) {
        $placeholders = array();
        foreach ($ticket_ids as $tid) {
            $tid = intval($tid);
            if ($tid <= 0) {
                continue;
            }
            $ph = ':t' . count($placeholders);
            $placeholders[] = $ph;
            $params[$ph] = $tid;
        }
        if (empty($placeholders)) {
            return array();
        }
        $filter = ' AND r.ticket_id IN (' . implode(',', $placeholders) . ')';
    }

    try {
        $stmt = $pdo->prepare("SELECT r.ticket_id, COUNT(*) AS unread
            FROM client_ticket_replies r
            INNER JOIN client_support_tickets t ON t.id = r.ticket_id
            WHERE r.sender_type = 'client'
              AND r.id > t.support_last_seen_reply_id" . $filter . "
            GROUP BY r.ticket_id");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        // The seen columns are added by init_ticket_chat_tables; without them
        // there is nothing to compare against, so nothing is unread.
        error_log("Unread count error: " . $e->getMessage());
        return array();
    }

    $counts = array();
    foreach ($rows as $r) {
        $counts[intval($r['ticket_id'])] = intval($r['unread']);
    }
    return $counts;
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
