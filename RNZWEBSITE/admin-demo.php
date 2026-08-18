<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'demo-storage.php';

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
session_set_cookie_params(0, '/', '', $isHttps, true);
session_start();
date_default_timezone_set('Asia/Manila');

$adminPassword = 'RNZ2026';
$maxLoginAttempts = 5;
$lockoutSeconds = 300;

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function request_value($request, $key, $default)
{
    return isset($request[$key]) ? $request[$key] : $default;
}

function rnz_csrf_token()
{
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);
        if ($bytes !== false) {
            return bin2hex($bytes);
        }
    }

    return sha1(uniqid(mt_rand(), true));
}

if (!function_exists('hash_equals')) {
    function hash_equals($knownString, $userString)
    {
        if (strlen($knownString) !== strlen($userString)) {
            return false;
        }

        $result = 0;
        for ($i = 0; $i < strlen($knownString); $i++) {
            $result |= ord($knownString[$i]) ^ ord($userString[$i]);
        }

        return $result === 0;
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = rnz_csrf_token();
}

if (isset($_GET['logout'])) {
  $_SESSION = array();
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $path = isset($params['path']) ? $params['path'] : '/';
    $domain = isset($params['domain']) ? $params['domain'] : '';
    $secure = isset($params['secure']) ? $params['secure'] : false;
    $httponly = isset($params['httponly']) ? $params['httponly'] : true;
    setcookie(session_name(), '', time() - 42000, $path, $domain, $secure, $httponly);
  }
  session_destroy();
  header('Location: ./admin-demo.php');
  exit;
}

$loginError = '';
$formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formType === 'login') {
  $lockoutUntil = isset($_SESSION['login_lockout_until']) ? (int)$_SESSION['login_lockout_until'] : 0;
  $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
  if ($lockoutUntil > time()) {
    $loginError = 'Too many attempts. Try again in 5 minutes.';
  } elseif (hash_equals($adminPassword, $password)) {
    session_regenerate_id(true);
    $_SESSION['rnz_admin'] = true;
    $_SESSION['csrf'] = rnz_csrf_token();
    unset($_SESSION['login_attempts'], $_SESSION['login_lockout_until']);
    header('Location: ./admin-demo.php');
    exit;
  } else {
    $attempts = (isset($_SESSION['login_attempts']) ? (int)$_SESSION['login_attempts'] : 0) + 1;
    $_SESSION['login_attempts'] = $attempts;
    if ($attempts >= $maxLoginAttempts) {
      $_SESSION['login_lockout_until'] = time() + $lockoutSeconds;
      $loginError = 'Too many attempts. Try again in 5 minutes.';
    } else {
      $loginError = 'Invalid admin password.';
    }
  }
}

$isLoggedIn = !empty($_SESSION['rnz_admin']);

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && $formType === 'request_action') {
    $csrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($_SESSION['csrf'], $csrf)) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $requestId = isset($_POST['request_id']) ? (string)$_POST['request_id'] : '';
    $action = isset($_POST['request_action']) ? (string)$_POST['request_action'] : '';
    $allowedStatuses = array('New', 'Contacted', 'Demo Booked', 'Closed');

    if ($action === 'delete') {
        rnz_delete_request($requestId);
    } elseif (in_array($action, $allowedStatuses, true)) {
        rnz_update_request_status($requestId, $action);
    }

    header('Location: ./admin-demo.php');
    exit;
}

$requests = rnz_load_requests();
$totalRequests = count($requests);
$newRequests = 0;
foreach ($requests as $request) {
    if (request_value($request, 'status', 'New') === 'New') {
        $newRequests++;
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>RNZ Demo Requests Admin</title>
    <link rel="icon" href="./new_logo_rnz_software_development_services_8Fg_icon.ico" />
    <style>
      :root {
        --charcoal: #151515;
        --paper: #fffdf8;
        --line: rgba(21, 21, 21, 0.1);
        --red: #f0441c;
        --orange: #ff6b22;
        --yellow: #ffb923;
      }

      * { box-sizing: border-box; }

      body {
        margin: 0;
        font-family: Manrope, Arial, sans-serif;
        color: var(--charcoal);
        background:
          linear-gradient(115deg, rgba(255, 185, 35, 0.14), transparent 34%),
          linear-gradient(155deg, transparent 20%, rgba(240, 68, 28, 0.08) 60%, transparent 78%),
          var(--paper);
      }

      .shell {
        width: min(1180px, calc(100% - 32px));
        margin: 0 auto;
        padding: 28px 0 56px;
      }

      .topbar, .panel, .login-card {
        border: 1px solid rgba(255, 255, 255, 0.75);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.82);
        box-shadow: 0 20px 60px rgba(21, 21, 21, 0.08);
        backdrop-filter: blur(20px);
      }

      .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
        padding: 16px;
      }

      .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 900;
      }

      .brand img {
        width: 42px;
        height: 42px;
        border-radius: 8px;
      }

      .button {
        border: 0;
        border-radius: 8px;
        background: var(--charcoal);
        color: white;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 16px;
        font-weight: 900;
        text-decoration: none;
      }

      .button.alt {
        border: 1px solid var(--line);
        background: white;
        color: var(--charcoal);
      }

      .button.danger {
        background: var(--red);
      }

      .hero {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        margin: 28px 0;
      }

      .metric {
        border: 1px solid var(--line);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.74);
        padding: 22px;
      }

      .metric strong {
        display: block;
        font-size: 34px;
      }

      .panel {
        overflow: hidden;
      }

      table {
        border-collapse: collapse;
        width: 100%;
      }

      th, td {
        border-bottom: 1px solid var(--line);
        padding: 16px;
        text-align: left;
        vertical-align: top;
      }

      th {
        background: rgba(21, 21, 21, 0.04);
        font-size: 12px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      td {
        font-size: 14px;
        line-height: 1.55;
      }

      .status {
        border-radius: 999px;
        display: inline-flex;
        padding: 6px 10px;
        background: rgba(255, 185, 35, 0.18);
        color: #8a4d00;
        font-size: 12px;
        font-weight: 900;
      }

      .actions {
        display: grid;
        gap: 8px;
        min-width: 150px;
      }

      select, input {
        width: 100%;
        border: 1px solid var(--line);
        border-radius: 8px;
        padding: 12px;
        font: inherit;
      }

      .login-wrap {
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px;
      }

      .login-card {
        width: min(460px, 100%);
        padding: 28px;
      }

      .error {
        border-radius: 8px;
        background: rgba(240, 68, 28, 0.12);
        color: #9d1f08;
        font-weight: 800;
        padding: 12px;
      }

      @media (max-width: 860px) {
        .topbar, .hero {
          grid-template-columns: 1fr;
        }

        .topbar {
          align-items: flex-start;
          flex-direction: column;
        }

        table, thead, tbody, th, td, tr {
          display: block;
        }

        thead {
          display: none;
        }

        tr {
          border-bottom: 1px solid var(--line);
          padding: 14px;
        }

        td {
          border: 0;
          padding: 8px 0;
        }
      }
    </style>
  </head>
  <body>
    <?php if (!$isLoggedIn): ?>
      <main class="login-wrap">
        <form class="login-card" method="post" action="./admin-demo.php">
          <input type="hidden" name="form_type" value="login" />
          <div class="brand">
            <img src="./new_logo_rnz_software_development_services_8Fg_icon.ico" alt="RNZ logo" />
            <div>
              <div>RNZ Admin</div>
              <small>Demo request dashboard</small>
            </div>
          </div>
          <h1>Admin Login</h1>
          <p>Enter the admin password to view demo requests.</p>
          <?php if ($loginError !== ''): ?>
            <p class="error"><?= e($loginError) ?></p>
          <?php endif; ?>
          <label>
            Password
            <input type="password" name="password" placeholder="Admin password" required />
          </label>
          <p>
            <button class="button" type="submit">Open Requests</button>
            <a class="button alt" href="./index.html#demo">Back to Website</a>
          </p>
        </form>
      </main>
    <?php else: ?>
      <main class="shell">
        <header class="topbar">
          <div class="brand">
            <img src="./new_logo_rnz_software_development_services_8Fg_icon.ico" alt="RNZ logo" />
            <div>
              <div>RNZ Demo Requests</div>
              <small>Book demo inquiries from the website</small>
            </div>
          </div>
          <div>
            <a class="button alt" href="./index.html#demo">Website Form</a>
            <a class="button" href="./admin-demo.php">Refresh</a>
            <a class="button danger" href="./admin-demo.php?logout=1">Logout</a>
          </div>
        </header>

        <section class="hero" aria-label="Request summary">
          <div class="metric">
            <span>Total Requests</span>
            <strong><?= e((string)$totalRequests) ?></strong>
          </div>
          <div class="metric">
            <span>New Requests</span>
            <strong><?= e((string)$newRequests) ?></strong>
          </div>
        </section>

        <section class="panel">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Customer</th>
                <th>Location</th>
                <th>Preferred System</th>
                <th>Notes</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($requests) === 0): ?>
                <tr>
                  <td colspan="7">No demo requests yet.</td>
                </tr>
              <?php endif; ?>
              <?php foreach ($requests as $request): ?>
                <tr>
                  <td><?= e(request_value($request, 'created_at', '')) ?></td>
                  <td>
                    <strong><?= e(request_value($request, 'name', '')) ?></strong><br />
                    <?= e(request_value($request, 'contact', '')) ?>
                  </td>
                  <td><?= e(request_value($request, 'location', '')) ?></td>
                  <td>
                    <?= e(request_value($request, 'preferred_pos', '')) ?>
                    <?php if (!empty($request['other_system'])): ?>
                      <br /><small><?= e($request['other_system']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td><?= nl2br(e(request_value($request, 'notes', ''))) ?></td>
                  <td><span class="status"><?= e(request_value($request, 'status', 'New')) ?></span></td>
                  <td>
                    <form class="actions" method="post" action="./admin-demo.php">
                      <input type="hidden" name="form_type" value="request_action" />
                      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>" />
                      <input type="hidden" name="request_id" value="<?= e(request_value($request, 'id', '')) ?>" />
                      <select name="request_action">
                        <option value="New"<?= request_value($request, 'status', 'New') === 'New' ? ' selected="selected"' : '' ?>>New</option>
                        <option value="Contacted"<?= request_value($request, 'status', 'New') === 'Contacted' ? ' selected="selected"' : '' ?>>Contacted</option>
                        <option value="Demo Booked"<?= request_value($request, 'status', 'New') === 'Demo Booked' ? ' selected="selected"' : '' ?>>Demo Booked</option>
                        <option value="Closed"<?= request_value($request, 'status', 'New') === 'Closed' ? ' selected="selected"' : '' ?>>Closed</option>
                      </select>
                      <button class="button" type="submit">Update</button>
                      <button class="button danger" type="submit" name="request_action" value="delete" onclick="return confirm('Delete this demo request?');">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      </main>
    <?php endif; ?>
  </body>
</html>
