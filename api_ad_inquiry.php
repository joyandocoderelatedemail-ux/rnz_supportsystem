<?php
// Client Portal Real-Time Ad Inquiry Chat API (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ad_inquiry_init.php';

$pdo = get_db_connection();
init_ad_inquiry_tables($pdo);

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_logged_in()) {
    echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
    exit;
}
if (!$pdo) {
    echo json_encode(array('success' => false, 'error' => 'Database connection failed'));
    exit;
}

$client = get_logged_client();
$accountnum = isset($client['accountnum']) ? $client['accountnum'] : '';
$clientname = isset($client['clientname']) ? $client['clientname'] : '';
$tradename = (isset($client['tradename']) && trim($client['tradename']) !== '') ? $client['tradename'] : $clientname;
if (trim((string)$tradename) === '') {
    $tradename = 'Client';
}

/**
 * Shape one chat message for the client thread.
 *
 * @param array $row
 * @return array
 */
function format_ad_message_for_client($row) {
    return array(
        'id' => intval($row['id']),
        'sender_type' => $row['sender_type'],
        'sender_name' => $row['sender_name'],
        'is_mine' => ($row['sender_type'] === 'client'),
        'message' => $row['message'],
        'formatted_date' => format_date($row['created_at'])
    );
}

// -----------------------------------------------------------
// POST: Start (or rejoin) the inquiry thread for one ad
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start') {
    $ad_title = isset($_POST['ad_title']) ? trim($_POST['ad_title']) : '';
    $ad_tag = isset($_POST['ad_tag']) ? trim($_POST['ad_tag']) : '';
    $ad_image = isset($_POST['ad_image']) ? trim($_POST['ad_image']) : '';

    if ($ad_title === '') {
        echo json_encode(array('success' => false, 'error' => 'No ad selected.'));
        exit;
    }

    $inquiry = find_open_client_ad_inquiry($pdo, $accountnum, $ad_title);
    if (!$inquiry) {
        $created = create_ad_inquiry($pdo, $accountnum, $clientname, $tradename, $ad_title, $ad_tag, $ad_image);
        if (!$created) {
            echo json_encode(array('success' => false, 'error' => 'Could not start the conversation. Please try again.'));
            exit;
        }
        $inquiry = get_client_ad_inquiry($pdo, $created['id'], $accountnum);
        if (!$inquiry) {
            echo json_encode(array('success' => false, 'error' => 'Could not start the conversation. Please try again.'));
            exit;
        }
    }

    $start_img = ad_inquiry_safe_image(isset($inquiry['ad_image']) ? $inquiry['ad_image'] : '');
    if ($start_img !== '' && !is_file(__DIR__ . '/' . $start_img)) {
        $start_img = '';
    }

    echo json_encode(array(
        'success' => true,
        'inquiry' => array(
            'id' => intval($inquiry['id']),
            'inquiry_number' => $inquiry['inquiry_number'],
            'ad_title' => $inquiry['ad_title'],
            'ad_tag' => isset($inquiry['ad_tag']) ? $inquiry['ad_tag'] : '',
            'ad_image' => $start_img,
            'status' => $inquiry['status']
        )
    ));
    exit;
}

// -----------------------------------------------------------
// POST: Send a client message
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

    $inquiry = get_client_ad_inquiry($pdo, $inquiry_id, $accountnum);
    if (!$inquiry) {
        echo json_encode(array('success' => false, 'error' => 'Conversation not found.'));
        exit;
    }
    if ($inquiry['status'] !== 'Open') {
        echo json_encode(array('success' => false, 'error' => 'This conversation is closed. Tap Inquire on the ad again to start a new one.'));
        exit;
    }

    $mid = post_ad_inquiry_message($pdo, $inquiry_id, 'client', $tradename, $text);
    if (!$mid) {
        echo json_encode(array('success' => false, 'error' => 'Your message could not be sent. Please try again.'));
        exit;
    }

    echo json_encode(array(
        'success' => true,
        'message' => array(
            'id' => intval($mid),
            'sender_type' => 'client',
            'sender_name' => $tradename,
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

$inquiry = get_client_ad_inquiry($pdo, $inquiry_id, $accountnum);
if (!$inquiry) {
    echo json_encode(array('success' => false, 'error' => 'Conversation not found.'));
    exit;
}

$rows = get_ad_inquiry_messages($pdo, $inquiry_id, $after_id);
$out = array();
foreach ($rows as $r) {
    $out[] = format_ad_message_for_client($r);
}

$poll_img = ad_inquiry_safe_image(isset($inquiry['ad_image']) ? $inquiry['ad_image'] : '');
if ($poll_img !== '' && !is_file(__DIR__ . '/' . $poll_img)) {
    $poll_img = '';
}

echo json_encode(array(
    'success' => true,
    'status' => $inquiry['status'],
    'inquiry' => array(
        'ad_title' => $inquiry['ad_title'],
        'ad_tag' => isset($inquiry['ad_tag']) ? $inquiry['ad_tag'] : '',
        'ad_image' => $poll_img
    ),
    'messages' => $out
));
exit;
