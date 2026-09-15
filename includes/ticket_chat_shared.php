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
    // Bumped to _v2 when reactions, edits and unsends were added on this side,
    // so a client already signed in re-runs the check once instead of skipping
    // straight past the new tables.
    if (!$force && isset($_SESSION['ticket_seen_typing_schema_ready_v2']) && $_SESSION['ticket_seen_typing_schema_ready_v2']) {
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

        // Stamped when a message is edited or taken back. The backend adds these
        // too - whichever side is opened first on a fresh install wins.
        $chk_edited = $pdo->query("SHOW COLUMNS FROM `client_ticket_replies` LIKE 'edited_at'");
        if ($chk_edited && $chk_edited->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `client_ticket_replies` ADD `edited_at` DATETIME NULL DEFAULT NULL");
        }
        $chk_unsent = $pdo->query("SHOW COLUMNS FROM `client_ticket_replies` LIKE 'unsent_at'");
        if ($chk_unsent && $chk_unsent->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `client_ticket_replies` ADD `unsent_at` DATETIME NULL DEFAULT NULL");
        }

        $_SESSION['ticket_seen_typing_schema_ready_v2'] = true;
        return true;
    } catch (PDOException $e) {
        error_log("Ticket seen/typing schema init error: " . $e->getMessage());
        return false;
    }
}

/**
 * The reactions a message can carry. Keys are what the database stores - the
 * emoji itself never goes near the 3-byte utf8 connection.
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
 * Every reaction on a ticket's messages, grouped per message, with the names
 * of the people who left them so the thread can show who reacted.
 *
 * @param PDO    $pdo
 * @param int    $ticket_id
 * @param string $actor_type 'client' or 'support' - marks the viewer's own reactions
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
        // The client reads "You" for their own account rather than their own
        // trade name repeated back at them.
        $is_mine = ($actor_name !== '' && $row['reactor_type'] === $actor_type && $row['reactor_name'] === $actor_name);
        $grouped[$rid][$key]['who'][] = $is_mine
            ? 'You'
            : $row['reactor_name'] . (($row['reactor_type'] === 'support') ? ' (Support)' : '');
        if ($is_mine) {
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
