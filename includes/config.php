<?php
// Configuration and Database Connection for PHP 5.6
if (session_status() == PHP_SESSION_NONE) {
    session_name('RNZ_CLIENT_SESSID');
    session_start();
}

// Environment Detection (Local vs Production)
$is_local = false;
$server_host = '';
if (isset($_SERVER['HTTP_HOST'])) {
    $server_host = $_SERVER['HTTP_HOST'];
} elseif (isset($_SERVER['SERVER_NAME'])) {
    $server_host = $_SERVER['SERVER_NAME'];
}

if (
    empty($server_host) ||
    strpos($server_host, 'localhost') !== false ||
    strpos($server_host, '127.0.0.1') !== false ||
    strpos($server_host, '::1') !== false ||
    substr($server_host, 0, 8) === '192.168.' ||
    substr($server_host, 0, 3) === '10.'
) {
    $is_local = true;
}

if ($is_local) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'vovoco5_rnz_supportsystem');
} else {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'vovoco5_dswzamljoxvz');
    define('DB_PASS', 'LAUj18%kbuED');
    define('DB_NAME', 'vovoco5_rnz_supportsystem');
}

/**
 * Get PDO Database Connection
 * @return PDO|null
 */
function get_db_connection() {
    static $pdo = null;
    if ($pdo === null) {
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
        );
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Local fallback if local DB has not been renamed from rnz_supportsystem
            if (DB_USER === 'root' && DB_PASS === '') {
                try {
                    $fallback_dsn = "mysql:host=" . DB_HOST . ";dbname=rnz_supportsystem;charset=utf8";
                    $pdo = new PDO($fallback_dsn, DB_USER, DB_PASS, $options);
                    return $pdo;
                } catch (PDOException $e2) {
                    throw $e2;
                }
            }
            throw $e;
        }
    }
    return $pdo;
}

/**
 * Sanitize string input
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Check if client is logged in
 */
function is_logged_in() {
    return isset($_SESSION['client_logged_in']) && $_SESSION['client_logged_in'] === true;
}

/**
 * Require client to be logged in, otherwise redirect to login page
 */
function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

/**
 * Get logged in client session array
 */
function get_logged_client() {
    if (is_logged_in() && isset($_SESSION['client_data'])) {
        return $_SESSION['client_data'];
    }
    return null;
}

/**
 * Format Date cleanly
 */
function format_date($date_str) {
    if (empty($date_str) || $date_str == 'N/A') {
        return 'N/A';
    }
    $timestamp = strtotime($date_str);
    if ($timestamp === false) {
        return $date_str;
    }
    return date('M d, Y', $timestamp);
}

/**
 * Get Tailwind CSS badge class by status
 */
function get_status_badge_class($status) {
    $status = strtolower(trim($status));
    if ($status === 'tech reply') {
        return 'bg-[#EB3E0B] text-white border-[#C32C0B]';
    } elseif ($status === 'done' || $status === 'resolved' || $status === 'closed' || $status === 'paid') {
        return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    } elseif ($status === 'in progress' || $status === 'working') {
        return 'bg-blue-50 text-blue-700 border-blue-200';
    } elseif ($status === 'pending' || $status === 'pending issue' || $status === 'unpaid') {
        return 'bg-amber-50 text-amber-700 border-amber-200';
    }
    return 'bg-slate-50 text-slate-700 border-slate-200';
}

/**
 * Safely upload ticket photo attachments (PNG, JPG, JPEG, WEBP, GIF)
 * Supports both single file and multiple files (e.g. name="attachments[]" or name="attachment")
 * @param string $file_key Name of the file input in $_FILES (default 'attachments')
 * @param string $subdir Target subdirectory inside uploads
 * @return string|false JSON-encoded array string of relative paths, or false if none
 */
function upload_ticket_photos($file_key = 'attachments', $subdir = 'ticket_attachments') {
    $actual_key = $file_key;
    if (!isset($_FILES[$actual_key]) && isset($_FILES['attachment'])) {
        $actual_key = 'attachment';
    }
    if (!isset($_FILES[$actual_key])) {
        return false;
    }

    $file_data = $_FILES[$actual_key];
    $upload_dir = __DIR__ . '/../uploads/' . $subdir . '/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }

    $allowed_exts = array('jpg', 'jpeg', 'png');
    $saved_paths = array();

    // Check if multiple files were uploaded (name is array)
    if (is_array($file_data['name'])) {
        $count = count($file_data['name']);
        for ($i = 0; $i < $count; $i++) {
            if (empty($file_data['name'][$i])) {
                continue;
            }
            if ($file_data['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            if ($file_data['size'][$i] > 15 * 1024 * 1024) {
                continue;
            }
            $ext = strtolower(pathinfo($file_data['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts)) {
                continue;
            }

            $new_filename = 'photo_' . date('Ymd_His') . '_' . $i . '_' . rand(1000, 9999) . '.' . $ext;
            $target_file = $upload_dir . $new_filename;

            if (move_uploaded_file($file_data['tmp_name'][$i], $target_file)) {
                $saved_paths[] = 'uploads/' . $subdir . '/' . $new_filename;
            }
        }
    } else {
        // Single file format
        if (!empty($file_data['name']) && $file_data['error'] === UPLOAD_ERR_OK && $file_data['size'] <= 15 * 1024 * 1024) {
            $ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed_exts)) {
                $new_filename = 'photo_' . date('Ymd_His') . '_' . rand(1000, 9999) . '.' . $ext;
                $target_file = $upload_dir . $new_filename;
                if (move_uploaded_file($file_data['tmp_name'], $target_file)) {
                    $saved_paths[] = 'uploads/' . $subdir . '/' . $new_filename;
                }
            }
        }
    }

    if (empty($saved_paths)) {
        return false;
    }

    return json_encode($saved_paths);
}

/**
 * Single photo upload wrapper for compatibility
 */
function upload_ticket_photo($file_key = 'attachment', $subdir = 'ticket_attachments') {
    $result = upload_ticket_photos($file_key, $subdir);
    if (!$result) return false;
    $arr = parse_ticket_attachments($result);
    return !empty($arr) ? $arr[0] : false;
}

/**
 * Parse database attachment_path column into an array of file path strings
 * @param string|null $raw_attachment JSON array string, comma-separated list, or single path
 * @return array List of valid relative path strings
 */
function parse_ticket_attachments($raw_attachment) {
    if (empty($raw_attachment)) {
        return array();
    }
    $raw_attachment = trim($raw_attachment);
    // Check if JSON array string
    if (substr($raw_attachment, 0, 1) === '[' && substr($raw_attachment, -1) === ']') {
        $decoded = json_decode($raw_attachment, true);
        if (is_array($decoded)) {
            $res = array();
            foreach ($decoded as $item) {
                if (!empty($item) && is_string($item)) {
                    $res[] = trim($item);
                }
            }
            return $res;
        }
    }
    // Check if comma separated
    if (strpos($raw_attachment, ',') !== false) {
        $parts = explode(',', $raw_attachment);
        $res = array();
        foreach ($parts as $p) {
            $p = trim($p);
            if (!empty($p)) $res[] = $p;
        }
        return $res;
    }
    return array($raw_attachment);
}
?>
