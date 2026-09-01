<?php
// API Endpoint for the dashboard Online Staff panel (PHP 5.6 Compatible)
// Doubles as the heartbeat that keeps the viewer marked online while the
// dashboard sits open. Staff accounts only - clients are never tracked.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/presence_init.php';

header('Content-Type: application/json');

if (!is_tech_logged_in()) {
    echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
    exit;
}

// The page the caller is actually looking at, so the panel does not report
// everyone as sitting on this endpoint.
$from_page = isset($_GET['page']) ? trim($_GET['page']) : '';
if ($from_page === '') {
    $from_page = 'Dashboard';
}
if (strlen($from_page) > 100) {
    $from_page = substr($from_page, 0, 100);
}

touch_user_presence($from_page);

$staff = get_online_staff();
$counts = count_staff_presence($staff);

echo json_encode(array(
    'success' => true,
    'online_count' => $counts['online'],
    'away_count' => $counts['away'],
    'total' => count($staff),
    'server_time' => date('g:i:s A'),
    'staff' => $staff
));
