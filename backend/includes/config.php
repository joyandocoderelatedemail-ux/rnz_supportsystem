<?php
// Configuration & Database Connection for Support Center Backend (PHP 5.6 Compatible)
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
    define('DB_USER', 'vovoco5');
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
                    die("Database Connection Error: " . $e->getMessage());
                }
            }
            die("Database Connection Error: " . $e->getMessage());
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
        header("Location: login.php");
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
?>
