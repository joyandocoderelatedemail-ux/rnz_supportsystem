<?php
// Configuration & Database Connection for Support Center Backend (PHP 5.6 Compatible)

// Application timezone (do not rely on php.ini; XAMPP defaults to Europe/Berlin)
date_default_timezone_set('Asia/Manila');
if (session_status() == PHP_SESSION_NONE) {
    session_name('RNZ_TECH_SESSID');
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
        // The session time zone is pinned to the same offset PHP runs on.
        // Without it MySQL keeps the host clock - identical to PHP on XAMPP,
        // but usually UTC or a US zone on a live server - and CURDATE() / NOW()
        // then disagree with the Manila timestamps every row is written with.
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8, time_zone = '" . date('P') . "'"
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
 * Check if technician/admin is logged in
 */
function is_tech_logged_in() {
    return isset($_SESSION['tech_logged_in']) && $_SESSION['tech_logged_in'] === true;
}

/**
 * Require technician to be logged in
 */
function require_tech_login() {
    if (!is_tech_logged_in()) {
        header("Location: ./");
        exit;
    }
}

/**
 * Get logged-in technician data array
 */
function get_logged_tech() {
    if (is_tech_logged_in() && isset($_SESSION['tech_data'])) {
        return $_SESSION['tech_data'];
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
    return date('M d, Y g:i A', $timestamp);
}

/**
 * Format a stored 24-hour time value (HH:MM or HH:MM:SS) as 12-hour with AM/PM.
 * Any other string is returned untouched so free-text time ranges survive.
 */
function format_time($time_str) {
    if (empty($time_str) || $time_str == 'N/A') {
        return 'N/A';
    }
    $time_str = trim($time_str);
    if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $time_str)) {
        return $time_str;
    }
    $timestamp = strtotime($time_str);
    if ($timestamp === false) {
        return $time_str;
    }
    return date('g:i A', $timestamp);
}

/**
 * Format a DATE column without the meaningless 12:00 AM time component.
 */
function format_date_only($date_str) {
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
 * Every colour a ticket row wears for a given status. Defined once because the
 * chat pop-up recolours rows live in JS from this same table - without that, a
 * ticket moved to In Progress keeps its red Pending styling until a reload.
 * Shared by the tickets center and the dashboard queue so both look identical.
 *
 * @param string $status
 * @return array
 */
function ticket_row_palette($status) {
    if ($status === 'Resolved' || $status === 'Closed') {
        // Resolved -> Green Row
        return array(
            'row'   => 'bg-emerald-50/70 hover:bg-emerald-100/80 border-b border-emerald-100 text-emerald-950',
            'num'   => 'text-emerald-700',
            'title' => 'text-emerald-950',
            'subj'  => 'text-emerald-900',
            'date'  => 'text-emerald-700 font-mono',
            'badge' => 'bg-emerald-100 text-emerald-800 border border-emerald-300',
            'btn'   => 'bg-emerald-100/90 hover:bg-emerald-600 text-emerald-700 hover:text-white'
        );
    }
    if ($status === 'Pending' || $status === 'Open') {
        // Pending -> Red Row
        return array(
            'row'   => 'bg-rose-50/70 hover:bg-rose-100/80 border-b border-rose-100 text-rose-950',
            'num'   => 'text-rose-700',
            'title' => 'text-rose-950',
            'subj'  => 'text-rose-900',
            'date'  => 'text-rose-700 font-mono',
            'badge' => 'bg-rose-100 text-rose-800 border border-rose-300',
            'btn'   => 'bg-rose-100/90 hover:bg-rose-600 text-rose-700 hover:text-white'
        );
    }
    if ($status === 'In Progress') {
        // In Progress -> Blue Row
        return array(
            'row'   => 'bg-blue-50/70 hover:bg-blue-100/80 border-b border-blue-100 text-blue-950',
            'num'   => 'text-blue-700',
            'title' => 'text-blue-950',
            'subj'  => 'text-blue-900',
            'date'  => 'text-blue-700 font-mono',
            'badge' => 'bg-blue-100 text-blue-800 border border-blue-300',
            'btn'   => 'bg-blue-100/90 hover:bg-blue-600 text-blue-700 hover:text-white'
        );
    }
    return array(
        'row'   => 'hover:bg-slate-50/80 text-slate-800',
        'num'   => 'text-[#EB3E0B]',
        'title' => 'text-slate-900',
        'subj'  => 'text-slate-800',
        'date'  => 'text-slate-500 font-mono',
        'badge' => get_status_badge_class($status),
        'btn'   => 'bg-slate-100 hover:bg-[#EB3E0B] text-slate-500 hover:text-white'
    );
}

/**
 * Extension -> max upload size (bytes) for one ticket chat attachment.
 * Videos and office documents run far larger than a phone photo, so each
 * kind gets its own ceiling instead of one limit fitting everything. This is
 * also the whitelist of extensions the chat is allowed to store.
 * @return array
 */
function get_ticket_attachment_size_limits() {
    return array(
        'jpg'  => 15 * 1024 * 1024,
        'jpeg' => 15 * 1024 * 1024,
        'png'  => 15 * 1024 * 1024,
        'pdf'  => 20 * 1024 * 1024,
        'xls'  => 20 * 1024 * 1024,
        'xlsx' => 20 * 1024 * 1024,
        'txt'  => 5 * 1024 * 1024,
        'mp4'  => 50 * 1024 * 1024,
        'mov'  => 50 * 1024 * 1024,
        'webm' => 50 * 1024 * 1024,
        'avi'  => 50 * 1024 * 1024
    );
}

/**
 * Safely upload ticket chat attachments: photos, videos, PDFs and Excel files.
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
    $upload_dir = __DIR__ . '/../../uploads/' . $subdir . '/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }

    $max_size_by_ext = get_ticket_attachment_size_limits();
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
            $ext = strtolower(pathinfo($file_data['name'][$i], PATHINFO_EXTENSION));
            if (!isset($max_size_by_ext[$ext])) {
                continue;
            }
            if ($file_data['size'][$i] > $max_size_by_ext[$ext]) {
                continue;
            }

            $new_filename = 'attach_' . date('Ymd_His') . '_' . $i . '_' . rand(1000, 9999) . '.' . $ext;
            $target_file = $upload_dir . $new_filename;

            if (move_uploaded_file($file_data['tmp_name'][$i], $target_file)) {
                $saved_paths[] = 'uploads/' . $subdir . '/' . $new_filename;
            }
        }
    } else {
        // Single file format
        if (!empty($file_data['name']) && $file_data['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
            if (isset($max_size_by_ext[$ext]) && $file_data['size'] <= $max_size_by_ext[$ext]) {
                $new_filename = 'attach_' . date('Ymd_His') . '_' . rand(1000, 9999) . '.' . $ext;
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
 * Renders one ticket chat attachment as whatever it actually is: an image
 * thumbnail, an inline video player, or a labelled download chip for anything
 * the browser cannot preview itself (PDF, Excel). Used by every page that
 * shows the reply thread server-side, outside the shared chat pop-up (which
 * renders the same three kinds in JS instead, for its live-polled replies).
 *
 * @param string $rel_path   Attachment path relative to the project root (e.g. "uploads/ticket_attachments/xxx.pdf")
 * @param string $url_prefix Prepended to reach it from the current page (e.g. "../" from inside backend/)
 * @param string $img_border Extra border colour class for the image thumbnail variant only
 * @return string HTML
 */
function build_chat_attachment_html($rel_path, $url_prefix = '', $img_border = 'border-slate-200') {
    $url = $url_prefix . ltrim($rel_path, '/\\');
    $safe_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $ext = strtolower(pathinfo($rel_path, PATHINFO_EXTENSION));

    if (in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
        return '<a href="' . $safe_url . '" target="_blank" rel="noopener" class="group relative inline-block">' .
                '<img src="' . $safe_url . '" alt="Attachment" class="h-28 w-auto max-w-[200px] object-cover rounded-2xl border ' . $img_border . ' shadow-xs group-hover:opacity-90 group-hover:scale-[1.02] transition-all">' .
                '<span class="absolute bottom-1 right-1 bg-black/60 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-md backdrop-blur-xs flex items-center gap-0.5 opacity-90 group-hover:opacity-100">' .
                    '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>' .
                    '<span>View</span>' .
                '</span>' .
            '</a>';
    }

    if (in_array($ext, array('mp4', 'mov', 'webm', 'avi'), true)) {
        return '<video controls preload="metadata" class="max-h-56 max-w-[260px] rounded-2xl border ' . $img_border . ' bg-black shadow-xs">' .
                '<source src="' . $safe_url . '">' .
                'Your browser cannot play this video. <a href="' . $safe_url . '" target="_blank" rel="noopener" class="underline">Download it instead</a>.' .
            '</video>';
    }

    $type_label = 'File';
    $icon_bg = 'bg-slate-100 text-slate-500';
    if ($ext === 'pdf') {
        $type_label = 'PDF Document';
        $icon_bg = 'bg-rose-50 text-rose-600';
    } elseif ($ext === 'xls' || $ext === 'xlsx') {
        $type_label = 'Excel Spreadsheet';
        $icon_bg = 'bg-emerald-50 text-emerald-600';
    } elseif ($ext === 'txt') {
        $type_label = 'Text File';
        $icon_bg = 'bg-sky-50 text-sky-600';
    }

    return '<a href="' . $safe_url . '" target="_blank" rel="noopener" ' .
            'class="flex items-center gap-2.5 bg-white border border-slate-200 rounded-2xl px-3 py-2.5 hover:border-[#FECDAA] hover:bg-[#FFF5ED]/50 transition-colors max-w-[220px] shadow-xs">' .
            '<span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 ' . $icon_bg . '">' .
                '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>' .
            '</span>' .
            '<span class="min-w-0">' .
                '<span class="block text-[11px] font-bold text-slate-800 truncate">' . htmlspecialchars($type_label, ENT_QUOTES, 'UTF-8') . '</span>' .
                '<span class="block text-[9px] font-bold text-[#EB3E0B] uppercase tracking-wider">Tap to open / download</span>' .
            '</span>' .
        '</a>';
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
