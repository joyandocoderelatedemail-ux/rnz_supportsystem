<?php
// AI Service Note Summariser (PHP 5.6 Compatible)
// Reads a ticket's chat thread and drafts the technician service note fields.
// The technician always reviews the draft before saving - this only pre-fills.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

$ai_config = __DIR__ . '/includes/ai_config.php';
if (file_exists($ai_config)) {
    require_once $ai_config;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// php.ini caps scripts at 30s, and on Windows that counts the time spent
// waiting on cURL. Without this the script is killed mid-call and returns an
// HTML fatal error, which the browser reports as a network failure.
// Room for all three attempts plus the backoff between them, otherwise PHP
// kills the script mid-retry and the browser sees an HTML error page.
@set_time_limit(defined('OPENROUTER_TIMEOUT') ? (OPENROUTER_TIMEOUT * 3 + 20) : 200);

if (!is_tech_logged_in()) {
    echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
    exit;
}

if (!defined('OPENROUTER_API_KEY') || OPENROUTER_API_KEY === '') {
    echo json_encode(array(
        'success' => false,
        'error' => 'AI summariser is not configured yet. Add your OpenRouter key to backend/includes/ai_config.php.'
    ));
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(array('success' => false, 'error' => 'Database connection failed'));
    exit;
}

$ticket_id = isset($_REQUEST['ticket_id']) ? intval($_REQUEST['ticket_id']) : 0;
if ($ticket_id <= 0) {
    echo json_encode(array('success' => false, 'error' => 'Invalid ticket ID'));
    exit;
}

$stmt_t = $pdo->prepare("SELECT id, ticket_number, subject, category, status, issue_description
    FROM client_support_tickets WHERE id = :id LIMIT 1");
$stmt_t->execute(array(':id' => $ticket_id));
$ticket = $stmt_t->fetch();

if (!$ticket) {
    echo json_encode(array('success' => false, 'error' => 'Ticket not found'));
    exit;
}

/**
 * Remote access credentials are pasted into the issue description by the client
 * portal. They must never leave this server, so the whole block is cut out
 * before anything is sent to the model.
 *
 * @param string $text
 * @return string
 */
function strip_remote_credentials($text) {
    $start = strpos($text, '=== ULTRAVIEWER REMOTE ACCESS DETAILS ===');
    if ($start === false) {
        return $text;
    }
    $end_marker = '=========================================';
    $end = strpos($text, $end_marker, $start);
    if ($end === false) {
        return substr($text, 0, $start) . '[remote access details withheld]';
    }
    return substr($text, 0, $start)
        . '[remote access details withheld]'
        . substr($text, $end + strlen($end_marker));
}

/**
 * The database runs on latin1, which cannot store the curly quotes and dashes a
 * model likes to produce. Fold them down to plain ASCII equivalents.
 *
 * @param string $text
 * @return string
 */
function flatten_to_latin1($text) {
    $map = array(
        "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'",
        "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
        "\xE2\x80\x93" => '-', "\xE2\x80\x94" => '-',
        "\xE2\x80\xA6" => '...', "\xC2\xA0" => ' ',
        "\xE2\x80\xA2" => '-'
    );
    $text = strtr($text, $map);
    // Anything still outside latin1 is dropped rather than stored as mojibake
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    return ($converted === false) ? $text : $converted;
}

// ---------------------------------------------------------------
// Build the transcript
// ---------------------------------------------------------------
$stmt_r = $pdo->prepare("SELECT sender_type, sender_name, message, attachment_path, created_at
    FROM client_ticket_replies WHERE ticket_id = :tid ORDER BY id ASC");
$stmt_r->execute(array(':tid' => $ticket_id));
$replies = $stmt_r->fetchAll();

$lines = array();
$lines[] = 'TICKET: ' . $ticket['ticket_number'];
$lines[] = 'SUBJECT: ' . $ticket['subject'];
$lines[] = 'CATEGORY: ' . $ticket['category'];
$lines[] = 'CURRENT STATUS: ' . $ticket['status'];
$lines[] = '';
$lines[] = 'REPORTED ISSUE:';
$lines[] = strip_remote_credentials($ticket['issue_description']);
$lines[] = '';
$lines[] = 'CONVERSATION:';

if (empty($replies)) {
    $lines[] = '(no replies yet)';
} else {
    foreach ($replies as $r) {
        $who = ($r['sender_type'] === 'support') ? 'Technician' : 'Client';
        $msg = trim(strip_remote_credentials($r['message']));

        if ($msg === '' && !empty($r['attachment_path'])) {
            $msg = '(sent photo attachment)';
        } elseif (strpos($msg, '=== HARDWARE DIAGNOSTIC LOG ===') !== false) {
            $msg = '(sent a hardware diagnostic log)';
        }
        if ($msg === '') {
            continue;
        }
        $lines[] = $who . ' (' . $r['sender_name'] . '): ' . $msg;
    }
}

$transcript = implode("\n", $lines);

// Test messages like "aaaaaaaa..." are pure token cost with no meaning. Collapse
// any character repeated more than 6 times down to a short run.
$transcript = preg_replace('/(.)\1{6,}/', '$1$1$1...', $transcript);

// A long thread still fits the 1M context, but there is no value in paying for
// the whole of a runaway thread - the tail is where the resolution lives.
$max_chars = 24000;
if (strlen($transcript) > $max_chars) {
    $transcript = "[earlier messages trimmed]\n" . substr($transcript, -$max_chars);
}

// ---------------------------------------------------------------
// Ask the model for the note fields
// ---------------------------------------------------------------
$system_prompt =
    "You write technician service notes for an IT support company. " .
    "You will be given a support ticket and its chat thread. " .
    "Summarise it into a service note.\n\n" .
    "Reply with ONE JSON object and nothing else. No markdown, no code fences, no commentary.\n" .
    "Keys, all required:\n" .
    "  reasonoftech - why the technician was needed, 1-2 sentences\n" .
    "  causeoftheissue - the diagnosed root cause, 1-2 sentences, \"\" if never established\n" .
    "  resso - what was actually done to fix it, 1-3 sentences, \"\" if nothing was done yet\n" .
    "  status - exactly one of: Done, Working, Pending Issue\n\n" .
    "Rules: write plain ASCII only, no curly quotes or em dashes. " .
    "Be factual and specific to this thread. Never invent steps that were not discussed. " .
    "Use \"Done\" only when the thread shows the problem was actually resolved.";

$payload = array(
    'model' => OPENROUTER_MODEL,
    'messages' => array(
        array('role' => 'system', 'content' => $system_prompt),
        array('role' => 'user', 'content' => $transcript)
    ),
    'temperature' => 0.2,
    'max_tokens' => 600,
    // Reasoning off, not merely hidden. "exclude" still makes the model think
    // before answering - measured at 22s and ~470 wasted tokens per summary
    // against 5s with it disabled, for the same quality of note.
    'reasoning' => array('enabled' => false)
);

/**
 * One call to OpenRouter.
 *
 * OpenRouter answers HTTP 200 even when the model behind it failed - the body
 * carries an "error" object instead of "choices", sometimes after a run of
 * keep-alive whitespace. So the body has to be inspected, not just the status.
 *
 * @param array  $payload
 * @param string $error_out   Set to a human readable reason on failure
 * @param bool   $retryable   Set to true when trying again is worth it
 * @return array|null Decoded response on success
 */
function openrouter_call($payload, &$error_out, &$retryable) {
    $error_out = '';
    $retryable = false;

    $ch = curl_init(OPENROUTER_URL);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => OPENROUTER_TIMEOUT,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'X-Title: RNZ Support System'
        )
    ));
    $raw = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    if ($curl_err !== '') {
        $error_out = 'Could not reach the AI service: ' . $curl_err;
        $retryable = true; // a timeout or dropped connection is worth one more go
        return null;
    }

    $decoded = json_decode(trim($raw), true);

    if (!is_array($decoded)) {
        $error_out = 'The AI service sent a reply that could not be read (HTTP ' . $http_code . ').';
        $retryable = true;
        return null;
    }

    // An error body can arrive under HTTP 200, so check it before the status
    if (isset($decoded['error'])) {
        $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Unknown AI service error';
        $code = isset($decoded['error']['code']) ? intval($decoded['error']['code']) : $http_code;

        $error_out = $msg;
        $retryable = in_array($code, array(408, 429, 500, 502, 503, 504), true)
            || stripos($msg, 'overloaded') !== false
            || stripos($msg, 'temporarily') !== false
            || stripos($msg, 'timeout') !== false;
        return null;
    }

    if ($http_code !== 200) {
        $error_out = 'AI service error: HTTP ' . $http_code;
        $retryable = ($http_code >= 500 || $http_code === 429);
        return null;
    }

    if (!isset($decoded['choices'][0]['message']['content'])
        || trim($decoded['choices'][0]['message']['content']) === '') {
        $error_out = 'The AI service returned an empty summary.';
        $retryable = true;
        return null;
    }

    return $decoded;
}

// The free NVIDIA endpoint is regularly "temporarily overloaded", and it
// recovers within seconds, so a couple of retries turn most failures into a
// slightly slower success instead of an error in the technician's face.
$decoded = null;
$last_error = '';
$attempts = 3;

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    $decoded = openrouter_call($payload, $last_error, $retryable);
    if ($decoded !== null) {
        break;
    }
    if (!$retryable || $attempt === $attempts) {
        break;
    }
    sleep($attempt * 2); // 2s, then 4s
}

if ($decoded === null) {
    echo json_encode(array(
        'success' => false,
        'error' => $last_error . ' (tried ' . $attempts . ' times - the free model is busy, try again in a moment.)'
    ));
    exit;
}

$content = trim($decoded['choices'][0]['message']['content']);

// Strip a code fence if the model wrapped the JSON in one anyway
$content = preg_replace('/^```(?:json)?\s*/i', '', $content);
$content = preg_replace('/\s*```$/', '', $content);

// Take the outermost JSON object, in case any stray prose survived
$first = strpos($content, '{');
$last = strrpos($content, '}');
if ($first === false || $last === false || $last <= $first) {
    echo json_encode(array('success' => false, 'error' => 'The AI reply was not in the expected format. Try again.'));
    exit;
}

$note = json_decode(substr($content, $first, $last - $first + 1), true);
if (!is_array($note)) {
    echo json_encode(array('success' => false, 'error' => 'The AI reply could not be read. Try again.'));
    exit;
}

$allowed_statuses = array('Done', 'Working', 'Pending Issue');
$status = isset($note['status']) ? trim($note['status']) : '';
if (!in_array($status, $allowed_statuses, true)) {
    $status = 'Working'; // Never let a bad value silently resolve a ticket
}

echo json_encode(array(
    'success' => true,
    'note' => array(
        'reasonoftech'    => flatten_to_latin1(isset($note['reasonoftech']) ? trim($note['reasonoftech']) : ''),
        'causeoftheissue' => flatten_to_latin1(isset($note['causeoftheissue']) ? trim($note['causeoftheissue']) : ''),
        'resso'           => flatten_to_latin1(isset($note['resso']) ? trim($note['resso']) : ''),
        'status'          => $status
    ),
    'replies_used' => count($replies)
));
exit;
