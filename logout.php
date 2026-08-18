<?php
// Logout Script
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

logout_client();
header("Location: ./");
exit;
?>
