<?php
// TEMPORARY DIAGNOSTIC - upload to backend/, open it, then DELETE it.
// Reports why the AI summariser cannot see a key. Never prints the key itself.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_tech_logged_in()) {
    die('Log in to the Support Center first, then reload this page.');
}

header('Content-Type: text/plain; charset=utf-8');

function yn($b) { return $b ? 'YES' : 'NO'; }

echo "AI SUMMARISER DIAGNOSTIC\n";
echo "========================\n\n";

echo "PHP version        : " . PHP_VERSION . "\n";
echo "cURL available     : " . yn(function_exists('curl_init')) . "\n";
echo "OpenSSL available  : " . yn(extension_loaded('openssl')) . "\n\n";

$cfg    = __DIR__ . '/includes/ai_config.php';
$sample = __DIR__ . '/includes/ai_config.sample.php';

echo "Looking for        : " . $cfg . "\n";
echo "ai_config.php      : " . (file_exists($cfg) ? 'FOUND' : 'MISSING') . "\n";
if (file_exists($cfg)) {
    echo "  readable by PHP  : " . yn(is_readable($cfg)) . "\n";
    echo "  size             : " . filesize($cfg) . " bytes\n";
}
echo "ai_config.sample   : " . (file_exists($sample) ? 'FOUND' : 'MISSING') . "\n";

$env = getenv('ATRIA_API_KEY');
echo "ATRIA_API_KEY env  : " . ($env ? 'SET (' . strlen($env) . " chars)" : 'not set') . "\n\n";

if (file_exists($cfg)) {
    require_once $cfg;
} elseif (file_exists($sample)) {
    require_once $sample;
}
if (!defined('AI_API_KEY') && defined('OPENROUTER_API_KEY')) { define('AI_API_KEY', OPENROUTER_API_KEY); }
if (!defined('AI_URL') && defined('OPENROUTER_URL')) { define('AI_URL', OPENROUTER_URL); }
if (!defined('AI_MODEL') && defined('OPENROUTER_MODEL')) { define('AI_MODEL', OPENROUTER_MODEL); }

echo "AI_API_KEY defined : " . yn(defined('AI_API_KEY')) . "\n";
if (defined('AI_API_KEY')) {
    $k = AI_API_KEY;
    echo "  length           : " . strlen($k) . " chars\n";
    echo "  looks like a key : " . yn(strlen($k) > 20) . "\n";
    echo "  starts with      : " . ($k === '' ? '(empty)' : substr($k, 0, 4) . '...') . "\n";
    echo "  stray whitespace : " . yn($k !== trim($k)) . "\n";
}
echo "AI_MODEL           : " . (defined('AI_MODEL') ? AI_MODEL : '(not defined)') . "\n";
echo "AI_URL             : " . (defined('AI_URL') ? AI_URL : '(not defined)') . "\n\n";

if (!defined('AI_API_KEY') || AI_API_KEY === '') {
    echo "RESULT: no key on this server. Create backend/includes/ai_config.php\n";
    echo "        (copy of ai_config.sample.php) with the key, or set ATRIA_API_KEY.\n";
    echo "\nDELETE THIS FILE when you are done.\n";
    exit;
}

echo "Calling the API (up to 20s)...\n";
$ch = curl_init(AI_URL);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array(
        'model' => AI_MODEL,
        'messages' => array(array('role' => 'user', 'content' => 'hi')),
        'max_tokens' => 5
    )),
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => array(
        'Authorization: Bearer ' . AI_API_KEY,
        'Content-Type: application/json',
        'Expect:'
    )
));
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_errno($ch) ? curl_error($ch) : '';
curl_close($ch);

echo "  HTTP status      : " . $code . "\n";
echo "  cURL error       : " . ($err === '' ? 'none' : $err) . "\n";

if ($err !== '') {
    echo "\nRESULT: this server cannot reach api.atria-asi.ai.\n";
    echo "        Ask the host to allow outbound HTTPS to api.atria-asi.ai:443.\n";
} elseif ($code === 401 || $code === 403) {
    echo "\nRESULT: the server reached the API but the key was rejected (HTTP " . $code . ").\n";
} elseif ($code === 200) {
    echo "\nRESULT: working. The summariser should now run on this server.\n";
} else {
    echo "\nRESULT: unexpected reply (HTTP " . $code . "): " . substr($raw, 0, 200) . "\n";
}

echo "\nDELETE THIS FILE when you are done.\n";
