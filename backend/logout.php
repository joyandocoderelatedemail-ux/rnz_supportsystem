<?php
// Technician Logout Handler (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/presence_init.php';

// Drop out of the Online Staff panel immediately instead of idling out of it.
$tech = get_logged_tech();
if ($tech && isset($tech['id'])) {
    clear_user_presence($tech['id']);
}

logout_tech();
header("Location: ./");
exit;
?>
