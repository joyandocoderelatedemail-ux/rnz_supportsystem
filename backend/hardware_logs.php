<?php
// Redirect legacy hardware logs to Manage Accounts Hub
$q = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['account']) ? trim($_GET['account']) : '');
header("Location: accounts.php?q=" . urlencode($q) . "&tab=logs");
exit;
?>
