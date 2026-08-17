<?php
// Technician Logout Handler (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

logout_tech();
header("Location: login.php");
exit;
?>
