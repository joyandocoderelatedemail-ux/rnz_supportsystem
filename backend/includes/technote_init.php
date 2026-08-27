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
