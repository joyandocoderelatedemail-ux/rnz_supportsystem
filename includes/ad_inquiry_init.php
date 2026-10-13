<?php
// Ad inquiry realtime chat - shared data layer.
//
// Included by BOTH the client portal (/index.php, /api_ad_inquiry.php) and the
// support backend (/backend/packages.php, /backend/api_ad_inquiry.php). Like
// packages_data.php it requires no config of its own: every function is handed
// the PDO connection it should work on.
// (PHP 5.6 Compatible)

/**
 * @return int longest chat message kept (characters)
 */
function ad_inquiry_max_message_len() {
    return 2000;
}

/**
 * Create both chat tables when missing.
 *
 * @param PDO $pdo
 * @return bool
 */
function init_ad_inquiry_tables($pdo) {
    if (!$pdo) {
        return false;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `client_ad_inquiries` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `inquiry_number` VARCHAR(50) NOT NULL UNIQUE,
            `accountnum` VARCHAR(100) NOT NULL,
            `clientname` VARCHAR(150) NOT NULL,
            `tradename` VARCHAR(150) NOT NULL,
            `ad_title` VARCHAR(150) NOT NULL,
            `ad_tag` VARCHAR(150) NOT NULL DEFAULT '',
            `ad_image` VARCHAR(255) NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Open',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `accountnum` (`accountnum`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `client_ad_inquiry_messages` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `inquiry_id` INT(11) NOT NULL,
            `sender_type` VARCHAR(20) NOT NULL,
            `sender_name` VARCHAR(100) NOT NULL,
            `message` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `inquiry_id` (`inquiry_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
        return true;
    } catch (PDOException $e) {
        error_log("Ad inquiry init error: " . $e->getMessage());
        return false;
    }
}

/**
 * Cap text at a character count without splitting multibyte characters.
 *
 * @param string $text
 * @param int $max
 * @return string
 */
function ad_inquiry_clip($text, $max) {
    $max = max(1, intval($max));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $max) {
            return mb_substr($text, 0, $max, 'UTF-8');
        }
        return $text;
    }
    if (strlen($text) > $max) {
        return substr($text, 0, $max);
    }
    return $text;
}

/**
 * The client's currently open thread for one ad, so tapping Inquire again
 * rejoins the same conversation instead of opening a duplicate.
 *
 * @param PDO $pdo
 * @param string $accountnum
 * @param string $ad_title
 * @return array|null
 */
function find_open_client_ad_inquiry($pdo, $accountnum, $ad_title) {
    if (!$pdo || trim((string)$accountnum) === '' || trim((string)$ad_title) === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM client_ad_inquiries
            WHERE accountnum = :acct AND ad_title = :title AND status = 'Open'
            ORDER BY id DESC LIMIT 1");
        $stmt->execute(array(':acct' => $accountnum, ':title' => $ad_title));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Ad inquiry lookup error: " . $e->getMessage());
        return null;
    }
    return $row ? $row : null;
}

/**
 * Open a new inquiry thread for one client + ad.
 *
 * @param PDO $pdo
 * @return array|null array('id' => int, 'inquiry_number' => string)
 */
function create_ad_inquiry($pdo, $accountnum, $clientname, $tradename, $ad_title, $ad_tag, $ad_image) {
    if (!$pdo) {
        return null;
    }
    $ad_title = ad_inquiry_clip(trim((string)$ad_title), 150);
    if ($ad_title === '') {
        return null;
    }
    try {
        $now = date('Y-m-d H:i:s');
        $number = 'INQ-' . date('Y') . '-' . rand(10000, 99999);
        $stmt = $pdo->prepare("INSERT INTO client_ad_inquiries
            (inquiry_number, accountnum, clientname, tradename, ad_title, ad_tag, ad_image, status, created_at, updated_at)
            VALUES (:num, :acct, :cname, :tname, :title, :tag, :img, 'Open', :c_at, :u_at)");
        $stmt->execute(array(
            ':num'   => $number,
            ':acct'  => $accountnum,
            ':cname' => ad_inquiry_clip((string)$clientname, 150),
            ':tname' => ad_inquiry_clip((string)$tradename, 150),
            ':title' => $ad_title,
            ':tag'   => ad_inquiry_clip(trim((string)$ad_tag), 150),
            ':img'   => (trim((string)$ad_image) !== '' ? ad_inquiry_clip(trim((string)$ad_image), 255) : null),
            ':c_at'  => $now,
            ':u_at'  => $now
        ));
        return array('id' => intval($pdo->lastInsertId()), 'inquiry_number' => $number);
    } catch (PDOException $e) {
        error_log("Ad inquiry create error: " . $e->getMessage());
        return null;
    }
}

/**
 * One inquiry row, ownership-checked for the client portal.
 *
 * @param PDO $pdo
 * @param int $id
 * @param string $accountnum
 * @return array|null
 */
function get_client_ad_inquiry($pdo, $id, $accountnum) {
    if (!$pdo || intval($id) <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM client_ad_inquiries WHERE id = :id AND accountnum = :acct LIMIT 1");
        $stmt->execute(array(':id' => intval($id), ':acct' => $accountnum));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Ad inquiry fetch error: " . $e->getMessage());
        return null;
    }
    return $row ? $row : null;
}

/**
 * One inquiry row for the support backend (no account scoping).
 *
 * @param PDO $pdo
 * @param int $id
 * @return array|null
 */
function get_ad_inquiry($pdo, $id) {
    if (!$pdo || intval($id) <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM client_ad_inquiries WHERE id = :id LIMIT 1");
        $stmt->execute(array(':id' => intval($id)));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Ad inquiry fetch error: " . $e->getMessage());
        return null;
    }
    return $row ? $row : null;
}

/**
 * Append one message to an open thread and bump its activity stamp.
 *
 * @param PDO $pdo
 * @param int $inquiry_id
 * @param string $sender_type 'client' or 'support'
 * @param string $sender_name
 * @param string $message
 * @return int|false new message id, or false when rejected
 */
function post_ad_inquiry_message($pdo, $inquiry_id, $sender_type, $sender_name, $message) {
    $inquiry_id = intval($inquiry_id);
    $message = ad_inquiry_clip(trim((string)$message), ad_inquiry_max_message_len());
    if (!$pdo || $inquiry_id <= 0 || $message === '') {
        return false;
    }
    $sender_type = ($sender_type === 'support') ? 'support' : 'client';
    $sender_name = ad_inquiry_clip(trim((string)$sender_name), 100);
    if ($sender_name === '') {
        $sender_name = ($sender_type === 'support') ? 'Support' : 'Client';
    }
    try {
        $chk = $pdo->prepare("SELECT status FROM client_ad_inquiries WHERE id = :id LIMIT 1");
        $chk->execute(array(':id' => $inquiry_id));
        $row = $chk->fetch();
        if (!$row || $row['status'] !== 'Open') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare("INSERT INTO client_ad_inquiry_messages
            (inquiry_id, sender_type, sender_name, message, created_at)
            VALUES (:iid, :stype, :sname, :msg, :c_at)");
        $ins->execute(array(
            ':iid'   => $inquiry_id,
            ':stype' => $sender_type,
            ':sname' => $sender_name,
            ':msg'   => $message,
            ':c_at'  => $now
        ));
        $mid = intval($pdo->lastInsertId());
        $touch = $pdo->prepare("UPDATE client_ad_inquiries SET updated_at = :now WHERE id = :id");
        $touch->execute(array(':now' => $now, ':id' => $inquiry_id));
        return $mid;
    } catch (PDOException $e) {
        error_log("Ad inquiry message error: " . $e->getMessage());
        return false;
    }
}

/**
 * Messages of one thread, oldest first.
 *
 * @param PDO $pdo
 * @param int $inquiry_id
 * @param int $after_id only rows newer than this
 * @return array
 */
function get_ad_inquiry_messages($pdo, $inquiry_id, $after_id = 0) {
    $out = array();
    if (!$pdo || intval($inquiry_id) <= 0) {
        return $out;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, inquiry_id, sender_type, sender_name, message, created_at
            FROM client_ad_inquiry_messages
            WHERE inquiry_id = :iid AND id > :after
            ORDER BY id ASC LIMIT 200");
        $stmt->execute(array(':iid' => intval($inquiry_id), ':after' => intval($after_id)));
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Ad inquiry thread error: " . $e->getMessage());
        return $out;
    }
    return $rows ? $rows : $out;
}

/**
 * Latest inquiry threads for the admin inbox, most recently active first,
 * each carrying its last message for the preview line.
 *
 * @param PDO $pdo
 * @param int $limit
 * @return array
 */
function get_recent_ad_inquiries($pdo, $limit = 10) {
    $out = array();
    if (!$pdo) {
        return $out;
    }
    $limit = max(1, intval($limit));
    try {
        $stmt = $pdo->prepare("SELECT i.*,
                (SELECT m.message FROM client_ad_inquiry_messages m WHERE m.inquiry_id = i.id ORDER BY m.id DESC LIMIT 1) AS last_msg,
                (SELECT m.sender_type FROM client_ad_inquiry_messages m WHERE m.inquiry_id = i.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
                (SELECT m.sender_name FROM client_ad_inquiry_messages m WHERE m.inquiry_id = i.id ORDER BY m.id DESC LIMIT 1) AS last_sender_name
            FROM client_ad_inquiries i
            ORDER BY i.updated_at DESC LIMIT " . $limit);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Ad inquiry inbox error: " . $e->getMessage());
        return $out;
    }
    return $rows ? $rows : $out;
}

/**
 * Inbox counters: total threads, open threads, and open threads waiting on
 * support (last message is from the client, or none yet).
 *
 * @param PDO $pdo
 * @return array
 */
function ad_inquiry_counts($pdo) {
    $out = array('total' => 0, 'open' => 0, 'awaiting' => 0);
    if (!$pdo) {
        return $out;
    }
    try {
        $row = $pdo->query("SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_cnt
            FROM client_ad_inquiries")->fetch();
        if ($row) {
            $out['total'] = intval($row['total']);
            $out['open'] = intval($row['open_cnt']);
        }
        $await = $pdo->query("SELECT COUNT(*) FROM client_ad_inquiries i
            WHERE i.status = 'Open'
            AND COALESCE((SELECT m.sender_type FROM client_ad_inquiry_messages m
                WHERE m.inquiry_id = i.id ORDER BY m.id DESC LIMIT 1), 'client') = 'client'")->fetchColumn();
        $out['awaiting'] = intval($await);
    } catch (PDOException $e) {
        error_log("Ad inquiry count error: " . $e->getMessage());
    }
    return $out;
}

/**
 * Normalize a stored ad image path for browser output: inside the project,
 * no traversal, or empty when it is not usable. Callers check is_file()
 * against their own base directory before serving it.
 *
 * @param string $rel_path
 * @return string
 */
function ad_inquiry_safe_image($rel_path) {
    $rel = str_replace('\\', '/', trim((string)$rel_path));
    $rel = ltrim($rel, '/');
    if ($rel === '') {
        return '';
    }
    if (strpos($rel, '..') !== false || strpos($rel, ':') !== false) {
        return '';
    }
    return $rel;
}
