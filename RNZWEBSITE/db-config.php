<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$hostHeader = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
$isLocal = (php_sapi_name() === 'cli') || empty($hostHeader) || 
    strpos($hostHeader, 'localhost') !== false ||
    strpos($hostHeader, '127.0.0.1') !== false ||
    strpos($hostHeader, '::1') !== false ||
    substr($hostHeader, 0, 8) === '192.168.' ||
    substr($hostHeader, 0, 3) === '10.';

if ($isLocal) {
    define('RNZ_DB_HOST', 'localhost');
    define('RNZ_DB_USER', 'root');
    define('RNZ_DB_PASS', '');
    define('RNZ_DB_NAME', 'vovoco5_rnz_supportsystem');
    define('RNZ_DB_PORT', 3306);
} else {
    define('RNZ_DB_HOST', 'localhost');
    define('RNZ_DB_USER', 'vovoco5_dswzamljoxvz');
    define('RNZ_DB_PASS', 'LAUj18%kbuED');
    define('RNZ_DB_NAME', 'vovoco5_rnz_supportsystem');
    define('RNZ_DB_PORT', 3306);
}
?>
