<?php
// Redirect legacy backend technotes to Manage Accounts Hub
$q = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['account']) ? trim($_GET['account']) : '');
header("Location: accounts.php?q=" . urlencode($q) . "&tab=notes");
exit;
?>
