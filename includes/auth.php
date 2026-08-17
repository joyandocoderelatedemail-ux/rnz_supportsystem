<?php
// Authentication Handler for PHP 5.6
require_once __DIR__ . '/config.php';

/**
 * Authenticate client with tradename and accountnum
 * 
 * @param string $username (trade name or client name)
 * @param string $password (account number)
 * @return array array('success' => bool, 'message' => string)
 */
function login_client($username, $password) {
    $username = trim($username);
    $password = trim($password);

    if (empty($username) || empty($password)) {
        return array('success' => false, 'message' => 'Please enter both Trade Name and Account Number.');
    }

    $pdo = get_db_connection();

    // Query bucket_client table using tradename as user and accountnum as password
    $stmt = $pdo->prepare("SELECT * FROM bucket_client WHERE LOWER(TRIM(tradename)) = LOWER(:user1) OR LOWER(TRIM(clientname)) = LOWER(:user2)");
    $stmt->execute(array(
        ':user1' => $username,
        ':user2' => $username
    ));
    $clients = $stmt->fetchAll();

    if (!$clients) {
        return array('success' => false, 'message' => 'Account not found. Please verify your Trade Name.');
    }

    $matched_client = null;
    foreach ($clients as $client) {
        // Compare accountnum (password)
        if (trim($client['accountnum']) === $password) {
            $matched_client = $client;
            break;
        }
    }

    if (!$matched_client) {
        return array('success' => false, 'message' => 'Invalid Account Number. Please check your credentials.');
    }

    // Set session data
    $_SESSION['client_logged_in'] = true;
    $_SESSION['client_data'] = array(
        'id' => $matched_client['id'],
        'accountnum' => $matched_client['accountnum'],
        'type' => $matched_client['type'],
        'clientname' => $matched_client['clientname'],
        'tradename' => $matched_client['tradename'],
        'address' => $matched_client['address'],
        'contactnum' => $matched_client['contactnum'],
        'emailaddress' => $matched_client['emailaddress'],
        'monthlyretainersfee' => $matched_client['monthlyretainersfee'],
        'outstandingbalance' => $matched_client['outstandingbalance']
    );

    return array('success' => true, 'message' => 'Login successful!');
}

/**
 * Logout client
 */
function logout_client() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
?>
