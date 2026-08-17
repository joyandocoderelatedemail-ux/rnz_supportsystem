<?php
// Technician & Admin Authentication Handler (PHP 5.6 Compatible)
require_once __DIR__ . '/config.php';

/**
 * Authenticate technician user with username and password against user table
 * 
 * @param string $username
 * @param string $password
 * @return array array('success' => bool, 'message' => string)
 */
function login_tech($username, $password) {
    $username = trim($username);
    $password = trim($password);

    if (empty($username) || empty($password)) {
        return array('success' => false, 'message' => 'Please enter both Username and Password.');
    }

    $pdo = get_db_connection();

    // Query user table
    $stmt = $pdo->prepare("SELECT * FROM user WHERE LOWER(TRIM(user)) = LOWER(:usr) LIMIT 1");
    $stmt->execute(array(':usr' => $username));
    $user_row = $stmt->fetch();

    if (!$user_row) {
        return array('success' => false, 'message' => 'User account not found. Please check your username.');
    }

    // Compare password
    if (trim($user_row['pass']) !== $password) {
        return array('success' => false, 'message' => 'Invalid password. Please check your credentials.');
    }

    $fullname = trim($user_row['fname'] . ' ' . $user_row['lname']);
    if (empty($fullname)) {
        $fullname = $user_row['user'];
    }

    // Set session data
    $_SESSION['tech_logged_in'] = true;
    $_SESSION['tech_data'] = array(
        'id' => $user_row['id'],
        'fname' => $user_row['fname'],
        'lname' => $user_row['lname'],
        'fullname' => $fullname,
        'user' => $user_row['user'],
        'emailadd' => $user_row['emailadd'],
        'accesslevel' => $user_row['accesslevel']
    );

    return array('success' => true, 'message' => 'Login successful!');
}

/**
 * Logout technician
 */
function logout_tech() {
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
