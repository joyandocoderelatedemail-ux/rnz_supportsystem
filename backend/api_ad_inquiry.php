<?php
// Support Center Real-Time Ad Inquiry Chat API (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/ad_inquiry_init.php';

$pdo = get_db_connection();
init_ad_inquiry_tables($pdo);

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_tech_logged_in()) {
    echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
    exit;
}
if (!user_has_page_access('packages')) {
    echo json_encode(array('success' => false, 'error' => 'Access Denied: You do not have permission to access client ads.'));
    exit;
}
if (!$pdo) {
    echo json_encode(array('success' => false, 'error' => 'Database connection failed'));
    exit;
}

$tech = get_logged_tech();
$tech_name = (isset($tech['fullname']) && trim($tech['fullname']) !== '') ? $tech['fullname'] : 'Support';

/**
 * Shape one chat message for the support thread.
 *
 * @param array $row
 * @return array
 */
function format_ad_message_for_support($row) {
    return array(
        'id' => intval($row['id']),
        'sender_type' => $row['sender_type'],
        'sender_name' => $row['sender_name'],
        'is_mine' => ($row['sender_type'] === 'support'),
        'message' => $row['message'],
        'formatted_date' => format_date($row['created_at'])
    );
}

// -----------------------------------------------------------
// POST: Send a support reply
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    $inquiry_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $text = isset($_POST['message']) ? trim($_POST['message']) : '';

    if ($inquiry_id <= 0) {
        echo json_encode(array('success' => false, 'error' => 'Invalid conversation.'));
        exit;
    }
    if ($text === '') {
        echo json_encode(array('success' => false, 'error' => 'Please enter a message.'));
        exit;
    }

    $inquiry = get_ad_inquiry($pdo, $inquiry_id);
    if (!$inquiry) {
        echo json_encode(array('success' => false, 'error' => 'Conversation not found.'));
        exit;
    }
    if ($inquiry['status'] !== 'Open') {
        echo json_encode(array('success' => false, 'error' => 'This conversation is Closed. Reopen it from the list to continue chatting.'));
        exit;
    }

    $mid = post_ad_inquiry_message($pdo, $inquiry_id, 'support', $tech_name, $text);
    if (!$mid) {
        echo json_encode(array('success' => false, 'error' => 'Your message could not be sent. Please try again.'));
        exit;
    }

    echo json_encode(array(
        'success' => true,
        'message' => array(
            'id' => intval($mid),
            'sender_type' => 'support',
            'sender_name' => $tech_name,
            'is_mine' => true,
            'message' => ad_inquiry_clip($text, ad_inquiry_max_message_len()),
            'formatted_date' => format_date(date('Y-m-d H:i:s'))
        )
    ));
    exit;
}

// -----------------------------------------------------------
// GET: Poll for messages
// -----------------------------------------------------------
$inquiry_id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
$after_id = isset($_GET['after_id']) ? intval($_GET['after_id']) : 0;

if ($inquiry_id <= 0) {
    echo json_encode(array('success' => false, 'error' => 'Invalid conversation.'));
    exit;
}

$inquiry = get_ad_inquiry($pdo, $inquiry_id);
if (!$inquiry) {
    echo json_encode(array('success' => false, 'error' => 'Conversation not found.'));
    exit;
}

$rows = get_ad_inquiry_messages($pdo, $inquiry_id, $after_id);
$out = array();
foreach ($rows as $r) {
    $out[] = format_ad_message_for_support($r);
}

$poll_img = ad_inquiry_safe_image(isset($inquiry['ad_image']) ? $inquiry['ad_image'] : '');
if ($poll_img !== '' && !is_file(__DIR__ . '/../' . $poll_img)) {
    $poll_img = '';
}

echo json_encode(array(
    'success' => true,
    'status' => $inquiry['status'],
    'inquiry' => array(
        'id' => intval($inquiry['id']),
        'inquiry_number' => $inquiry['inquiry_number'],
        'accountnum' => $inquiry['accountnum'],
        'clientname' => $inquiry['clientname'],
        'tradename' => $inquiry['tradename'],
        'ad_title' => $inquiry['ad_title'],
        'ad_tag' => isset($inquiry['ad_tag']) ? $inquiry['ad_tag'] : '',
        'ad_image' => $poll_img
    ),
    'messages' => $out
));
exit;
