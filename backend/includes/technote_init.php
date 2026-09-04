<?php
// Technician Service Note Extras: link a note back to the ticket it came from
// (PHP 5.6 Compatible)
require_once __DIR__ . '/config.php';

/**
 * Patch bucket_technotes so a note can record which support ticket it was
 * logged against. Notes saved from anywhere else keep ticket_id = 0.
 * Safe to call on every page load.
 *
 * @return bool
 */
function init_technote_tables($force = false) {
    // The footer runs on every page, so the schema check runs once per session
    if (!$force && isset($_SESSION['technote_schema_ready']) && $_SESSION['technote_schema_ready']) {
        return true;
    }

    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $chk_col = $pdo->query("SHOW COLUMNS FROM `bucket_technotes` LIKE 'ticket_id'");
        if ($chk_col && $chk_col->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `bucket_technotes`
                ADD `ticket_id` INT(11) NOT NULL DEFAULT 0,
                ADD KEY `ticket_id` (`ticket_id`)");
        }

        $_SESSION['technote_schema_ready'] = true;
        return true;
    } catch (PDOException $e) {
        error_log("Tech note init error: " . $e->getMessage());
        return false;
    }
}

/**
 * The service note logged against a ticket - a ticket keeps one, and it is the
 * first one saved, so the oldest row wins if older data ever holds two.
 *
 * @param PDO $pdo
 * @param int $ticket_id
 * @return array|null row from bucket_technotes, or null when none is logged
 */
function get_ticket_service_note($pdo, $ticket_id) {
    $ticket_id = intval($ticket_id);
    if (!$pdo || $ticket_id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, xdate, clientname, techname, reasonoftech, causeoftheissue, resso, status
            FROM bucket_technotes WHERE ticket_id = :tid ORDER BY id ASC LIMIT 1");
        $stmt->execute(array(':tid' => $ticket_id));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Ticket service note lookup error: " . $e->getMessage());
        return null;
    }
    return $row ? $row : null;
}

/**
 * A logged service note can only be rewritten by the technician who saved it.
 * Everyone else - including whoever picks the ticket up next - reads it.
 *
 * @param array|null $note      row from get_ticket_service_note()
 * @param string     $techname  signed-in technician's full name
 * @return bool
 */
function can_edit_service_note($note, $techname) {
    if (!$note || !isset($note['techname'])) {
        return false;
    }
    $author = strtolower(trim($note['techname']));
    $viewer = strtolower(trim($techname));
    return ($author !== '' && $author === $viewer);
}
