<?php
// Support Center Hardware Inventory Hub (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory_init.php';

require_page_access('inventory');

// Auto initialize inventory tables & default catalog
init_inventory_tables();

$pdo = get_db_connection();
$tech = get_logged_tech();
$tech_name = $tech ? $tech['fullname'] : 'Support Tech';

$msg = isset($_GET['msg']) ? sanitize($_GET['msg']) : '';
$msg_type = 'success';
$msg_text = '';

if ($msg === 'item_added') {
    $msg_text = 'Hardware item successfully added to inventory.';
} elseif ($msg === 'quantity_adjusted') {
    $msg_text = 'Stock quantity successfully updated and logged in movement audit.';
} elseif ($msg === 'pullout_success') {
    $item_name = isset($_GET['item']) ? sanitize($_GET['item']) : 'Hardware Item';
    $client_name = isset($_GET['client']) ? sanitize($_GET['client']) : 'Client';
    $msg_text = 'Hardware Pull-Out recorded successfully! Processed ' . $item_name . ' for ' . $client_name . ' and automatically updated stock & client service notes.';
} elseif ($msg === 'item_updated') {
    $msg_text = 'Item details successfully updated.';
} elseif ($msg === 'item_deleted') {
    $msg_text = 'Inventory item removed successfully.';
} elseif ($msg === 'item_disabled') {
    $disabled_item = isset($_GET['item']) ? sanitize($_GET['item']) : 'Hardware item';
    $msg_text = $disabled_item . ' is now DISABLED. It is hidden from the client ordering catalog, while its stock, pricing and movement history are kept. You can re-enable it anytime.';
} elseif ($msg === 'item_enabled') {
    $enabled_item = isset($_GET['item']) ? sanitize($_GET['item']) : 'Hardware item';
    $msg_text = $enabled_item . ' is now ACTIVE again and visible to clients in the ordering catalog.';
} elseif ($msg === 'synced') {
    $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
    $msg_text = 'Synced with Client Portal Hardware catalog! ' . $count . ' new item(s) imported.';
} elseif ($msg === 'error') {
    $msg_type = 'error';
    $msg_text = isset($_GET['err_msg']) ? sanitize($_GET['err_msg']) : 'An error occurred during the operation.';
}

/**
 * Strict Security Access Code verification for the actions that change what an
 * item is (editing details, disabling/enabling client visibility). Level 2
 * accounts must supply a code that passes a format check and then matches their
 * saved code; 3 wrong codes lock these actions for 60 seconds. Level 1 is
 * already blocked earlier by check_tech_action_permission().
 *
 * @param string $action_code Raw code from $_POST['action_access_code']
 * @param string $what        Short phrase for the messages, e.g. "update this hardware item"
 * @return string Empty string when allowed, otherwise the reason to show the user
 */
function verify_inventory_action_code($action_code, $what) {
    if (get_logged_tech_access_tier() !== 2) {
        return ''; // Level 3 needs no code; Level 1 never reaches this point
    }

    $lock_until = isset($_SESSION['inv_action_code_locked_until']) ? intval($_SESSION['inv_action_code_locked_until']) : 0;
    if ($lock_until > time()) {
        return "Inventory changes are temporarily locked after 3 incorrect Security Access Codes. Please wait " .
            ($lock_until - time()) . " second(s) and try again.";
    }

    // Format validation - these are typing mistakes, so they do not count
    // against the failed attempt limit.
    if ($action_code === '') {
        return "Security Access Code is required to " . $what . ".";
    }
    if (preg_match('/\s/', $action_code)) {
        return "Invalid Security Access Code format: the code cannot contain spaces.";
    }
    if (strlen($action_code) < 4 || strlen($action_code) > 32) {
        return "Invalid Security Access Code format: the code must be 4 to 32 characters long.";
    }

    // Identity verification against the technician's saved access code
    $code_tech = get_logged_tech();
    $code_uid = ($code_tech && isset($code_tech['id'])) ? intval($code_tech['id']) : 0;
    if (!verify_user_access_code($code_uid, $action_code)) {
        $tries = isset($_SESSION['inv_action_code_tries']) ? intval($_SESSION['inv_action_code_tries']) + 1 : 1;
        $deny_msg = "Access Denied: Incorrect Security Access Code. Nothing was changed.";
        if ($tries >= 3) {
            $_SESSION['inv_action_code_tries'] = 0;
            $_SESSION['inv_action_code_locked_until'] = time() + 60;
            $deny_msg .= " Inventory changes are now locked for 60 seconds after 3 failed attempts.";
        } else {
            $_SESSION['inv_action_code_tries'] = $tries;
            $deny_msg .= " " . (3 - $tries) . " attempt(s) left before a 60 second lockout.";
        }
        return $deny_msg;
    }

    // Verified - clear any earlier failed attempts for this session
    $_SESSION['inv_action_code_tries'] = 0;
    $_SESSION['inv_action_code_locked_until'] = 0;
    return '';
}

// ----------------------------------------------------
// Handle POST Actions
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Enforce Level 1 (View Only) block and Level 2 (Security Code) across ALL inventory actions
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
    $perm_check = check_tech_action_permission($action_code);
    if (!$perm_check['allowed']) {
        header("Location: inventory.php?msg=error&err_msg=" . urlencode($perm_check['message']));
        exit;
    }

    // 1. Pull Out Item (Hardware Pull-out from client or to client with auto service note)
    if ($action === 'pull_out_item') {

        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $pullout_direction = isset($_POST['pullout_direction']) ? trim($_POST['pullout_direction']) : 'from_client';
        $amount = isset($_POST['amount']) ? intval($_POST['amount']) : 1;
        if ($amount < 1) $amount = 1;
        $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
        $client_name = isset($_POST['client_name']) ? trim($_POST['client_name']) : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : 'Defective Unit / Pull-out for Diagnostics';
        $custom_notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $serial_number = isset($_POST['serial_number']) ? trim($_POST['serial_number']) : '';
        $condition_status = isset($_POST['condition_status']) ? trim($_POST['condition_status']) : 'Defective / For Diagnostics';

        $auto_technote = isset($_POST['auto_technote']) ? intval($_POST['auto_technote']) : 1;

        if ($item_id <= 0) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode("Please select a valid hardware item."));
            exit;
        }
        if (empty($accountnum)) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode("Please select or specify the client account for this pull out."));
            exit;
        }

        try {
            $stmt_cur = $pdo->prepare("SELECT id, item_code, name, quantity, qty_good, qty_defective, qty_damaged, qty_diagnostic FROM support_inventory_items WHERE id = :id LIMIT 1");
            $stmt_cur->execute(array(':id' => $item_id));
            $item_data = $stmt_cur->fetch();

            if (!$item_data) {
                header("Location: inventory.php?msg=error&err_msg=" . urlencode("Hardware item not found in inventory."));
                exit;
            }

            $prev_qty = intval($item_data['quantity']);
            $new_qty = $prev_qty;
            $qty_change = 0;
            $now = date('Y-m-d H:i:s');
            $today = date('Y-m-d');

            // Look up client details from bucket_client if needed
            $stmt_c = $pdo->prepare("SELECT tradename, clientname, address FROM bucket_client WHERE accountnum = :acct LIMIT 1");
            $stmt_c->execute(array(':acct' => $accountnum));
            $c_row = $stmt_c->fetch();
            if ($c_row) {
                if (empty($client_name)) {
                    $client_name = !empty($c_row['tradename']) ? $c_row['tradename'] : $c_row['clientname'];
                }
                if (empty($address)) {
                    $address = $c_row['address'];
                }
            }
            if (empty($client_name)) {
                $client_name = 'Account #' . $accountnum;
            }

            if ($pullout_direction === 'to_client') {
                // Only good/functional units may be released to a client
                $good_on_hand = intval($item_data['qty_good']);
                if ($amount > $good_on_hand) {
                    header("Location: inventory.php?msg=error&err_msg=" . urlencode(
                        "Cannot deploy " . $amount . " unit(s) of " . $item_data['name'] . ": only " . $good_on_hand .
                        " in Good / Functional stock. Repair or re-classify the units first."));
                    exit;
                }

                $qty_change = -$amount;
                $change_label = 'Pull Out (To Client)';
                $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET qty_good = qty_good - :amt, updated_at = :now WHERE id = :id");
                $stmt_up->execute(array(':amt' => $amount, ':now' => $now, ':id' => $item_id));
                $new_qty = resync_item_total_quantity($pdo, $item_id);
            } else {
                // A unit pulled back is physically in the office, so it always enters
                // stock - the chosen Item Condition decides which bucket it lands in.
                $change_label = 'Pull Out (From Client)';
                $target_column = condition_to_stock_column($condition_status);
                $qty_change = $amount;
                $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET `" . $target_column . "` = `" . $target_column . "` + :amt, updated_at = :now WHERE id = :id");
                $stmt_up->execute(array(':amt' => $amount, ':now' => $now, ':id' => $item_id));
                $new_qty = resync_item_total_quantity($pdo, $item_id);
            }

            // Pulling a unit back means the client no longer has it, so remove it
            // from their Software & Hardware records (client_assets).
            $assets_removed = 0;
            $assets_shortfall = 0;
            if ($pullout_direction !== 'to_client') {
                $remaining = $amount;

                // Prefer the exact record when a serial number was supplied,
                // then work through the client's other records for this item.
                $stmt_owned = $pdo->prepare("SELECT id, quantity, serial_number FROM client_assets
                    WHERE accountnum = :acct AND item_id = :iid AND asset_type = 'Hardware'
                    ORDER BY (serial_number = :serial) DESC, id ASC");
                $stmt_owned->execute(array(
                    ':acct' => $accountnum,
                    ':iid' => $item_id,
                    ':serial' => $serial_number
                ));
                $owned_rows = $stmt_owned->fetchAll();

                foreach ($owned_rows as $owned) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $owned_qty = intval($owned['quantity']);

                    if ($owned_qty <= $remaining) {
                        // Whole record consumed - drop it from the client's list
                        $stmt_del = $pdo->prepare("DELETE FROM client_assets WHERE id = :id");
                        $stmt_del->execute(array(':id' => $owned['id']));
                        $remaining -= $owned_qty;
                        $assets_removed += $owned_qty;
                    } else {
                        // Only part of the record was pulled out - keep the remainder
                        $left = $owned_qty - $remaining;
                        $stmt_dec = $pdo->prepare("UPDATE client_assets
                            SET quantity = :qty, total_amount = unit_price * :qty2, updated_at = :now
                            WHERE id = :id");
                        $stmt_dec->execute(array(
                            ':qty' => $left,
                            ':qty2' => $left,
                            ':now' => $now,
                            ':id' => $owned['id']
                        ));
                        $assets_removed += $remaining;
                        $remaining = 0;
                    }
                }

                $assets_shortfall = $remaining;
            }

            // Build compiled notes for inventory movement log
            $log_notes_parts = array();
            $log_notes_parts[] = "Reason: " . $reason;
            if (!empty($serial_number)) {
                $log_notes_parts[] = "S/N: " . $serial_number;
            }
            if (!empty($condition_status)) {
                $log_notes_parts[] = "Condition: " . $condition_status;
            }
            if ($pullout_direction !== 'to_client') {
                $short_labels = get_stock_condition_short_labels();
                $log_notes_parts[] = "Added to " . $short_labels[condition_to_stock_column($condition_status)] . " stock";
                if ($assets_removed > 0) {
                    $log_notes_parts[] = "Removed " . $assets_removed . " unit(s) from client records";
                }
                if ($assets_shortfall > 0) {
                    $log_notes_parts[] = "NOTE: " . $assets_shortfall . " unit(s) were not on the client's record";
                }
            }
            if (!empty($custom_notes)) {
                $log_notes_parts[] = "Notes: " . $custom_notes;
            }
            $compiled_notes = implode(' | ', $log_notes_parts);

            // 1. Insert Inventory Movement Log
            $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
                (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, accountnum, client_name, notes, created_at) 
                VALUES (:item_id, :tech, :type, :change, :prev, :new, :acct, :client, :notes, :now)");
            $stmt_log->execute(array(
                ':item_id' => $item_id,
                ':tech' => $tech_name,
                ':type' => $change_label,
                ':change' => $qty_change,
                ':prev' => $prev_qty,
                ':new' => $new_qty,
                ':acct' => $accountnum,
                ':client' => $client_name,
                ':notes' => $compiled_notes,
                ':now' => $now
            ));

            // 1b. Deploying to a client also records the unit in their Software & Hardware
            //     list on accounts.php (client_assets), so the two views stay in step.
            if ($pullout_direction === 'to_client') {
                $stmt_price = $pdo->prepare("SELECT cost_price, selling_price, unit_price FROM support_inventory_items WHERE id = :id LIMIT 1");
                $stmt_price->execute(array(':id' => $item_id));
                $price_row = $stmt_price->fetch();

                $asset_unit_price = 0.00;
                if ($price_row) {
                    if (isset($price_row['selling_price']) && floatval($price_row['selling_price']) > 0) {
                        $asset_unit_price = floatval($price_row['selling_price']);
                    } elseif (isset($price_row['unit_price'])) {
                        $asset_unit_price = floatval($price_row['unit_price']);
                    }
                }

                $asset_notes_parts = array('Deployed via inventory pull-out.');
                if (!empty($reason)) {
                    $asset_notes_parts[] = 'Reason: ' . $reason;
                }
                if (!empty($custom_notes)) {
                    $asset_notes_parts[] = $custom_notes;
                }

                $stmt_ca = $pdo->prepare("INSERT INTO client_assets 
                    (accountnum, asset_type, item_id, item_code, name, serial_number, quantity, 
                     unit_price, total_amount, notes, warranty_status, recorded_by, created_at, updated_at) 
                    VALUES 
                    (:acct, 'Hardware', :iid, :icode, :name, :serial, :qty, 
                     :price, :total, :notes, 'Inactive', :by, :created, :updated)");
                $stmt_ca->execute(array(
                    ':acct' => $accountnum,
                    ':iid' => $item_id,
                    ':icode' => $item_data['item_code'],
                    ':name' => $item_data['name'],
                    ':serial' => $serial_number,
                    ':qty' => $amount,
                    ':price' => $asset_unit_price,
                    ':total' => ($asset_unit_price * $amount),
                    ':notes' => implode(' ', $asset_notes_parts),
                    ':by' => $tech_name,
                    ':created' => $now,
                    ':updated' => $now
                ));
            }

            // 2. Automatically insert into bucket_technotes (Client Service Notes)
            if ($auto_technote === 1) {
                $tech_reason = "[Hardware Pull-Out] " . $item_data['item_code'] . " - " . $item_data['name'] . " (Qty: " . $amount . ")";
                $tech_cause = "Pull-Out Reason: " . $reason;
                $tech_resso_parts = array();
                $tech_resso_parts[] = ($pullout_direction === 'to_client') ? "Hardware deployed / released to client by " . $tech_name . "." : "Hardware pulled out from client by " . $tech_name . ".";
                if (!empty($serial_number)) {
                    $tech_resso_parts[] = "Serial Number: " . $serial_number . ".";
                }
                if (!empty($condition_status)) {
                    $tech_resso_parts[] = "Condition: " . $condition_status . ".";
                }
                if (!empty($custom_notes)) {
                    $tech_resso_parts[] = "Remarks: " . $custom_notes;
                }
                $tech_resso = implode(" ", $tech_resso_parts);
                $tech_status = (strpos(strtolower($condition_status), 'defective') !== false || strpos(strtolower($reason), 'defective') !== false || strpos(strtolower($reason), 'repair') !== false) ? 'For Repair' : 'Completed';

                $stmt_tn = $pdo->prepare("INSERT INTO bucket_technotes 
                    (accountnum, xdate, clientname, address, techname, reasonoftech, causeoftheissue, resso, status) 
                    VALUES (:acct, :xdate, :cname, :addr, :tname, :reason, :cause, :resso, :status)");
                $stmt_tn->execute(array(
                    ':acct' => $accountnum,
                    ':xdate' => $today,
                    ':cname' => $client_name,
                    ':addr' => !empty($address) ? $address : 'N/A',
                    ':tname' => $tech_name,
                    ':reason' => $tech_reason,
                    ':cause' => $tech_cause,
                    ':resso' => $tech_resso,
                    ':status' => $tech_status
                ));
            }

            header("Location: inventory.php?msg=pullout_success&item=" . urlencode($item_data['name']) . "&client=" . urlencode($client_name));
            exit;
        } catch (PDOException $e) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 2. Add New Item
    if ($action === 'add_item') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $item_code = isset($_POST['item_code']) ? trim($_POST['item_code']) : '';
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
        $min_threshold = isset($_POST['min_threshold']) ? intval($_POST['min_threshold']) : 5;
        $cost_price = isset($_POST['cost_price']) ? floatval($_POST['cost_price']) : 0.00;
        $selling_price = isset($_POST['selling_price']) ? floatval($_POST['selling_price']) : (isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : 0.00);
        if ($cost_price < 0) $cost_price = 0.00;
        if ($selling_price < 0) $selling_price = 0.00;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if (empty($name)) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode("Item name is required."));
            exit;
        }

        if (empty($item_code)) {
            $item_code = 'HW-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 4)) . '-' . rand(100, 999);
        }

        try {
            $now = date('Y-m-d H:i:s');
            $stmt_check = $pdo->prepare("SELECT id FROM support_inventory_items WHERE item_code = :code LIMIT 1");
            $stmt_check->execute(array(':code' => $item_code));
            if ($stmt_check->fetch()) {
                $item_code .= '-' . rand(10, 99);
            }

            $stmt_in = $pdo->prepare("INSERT INTO support_inventory_items 
                (item_code, name, category, description, image_path, quantity, qty_good, min_threshold, cost_price, selling_price, unit_price, location, status, created_at, updated_at) 
                VALUES (:code, :name, 'Hardware', :description, NULL, :qty, :qty, :min, :cost, :price, :price, 'Main Storage', 'Active', :now, :now)");
            $stmt_in->execute(array(
                ':code' => $item_code,
                ':name' => $name,
                ':description' => $description,
                ':qty' => $quantity,
                ':min' => $min_threshold,
                ':cost' => $cost_price,
                ':price' => $selling_price,
                ':now' => $now
            ));
            $item_id = $pdo->lastInsertId();

            // Log Initial stock
            $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
                (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, notes, created_at) 
                VALUES (:item_id, :tech, 'Initial Stock', :qty, 0, :qty, 'Added new inventory item', :now)");
            $stmt_log->execute(array(
                ':item_id' => $item_id,
                ':tech' => $tech_name,
                ':qty' => $quantity,
                ':now' => $now
            ));

            header("Location: inventory.php?msg=item_added");
            exit;
        } catch (PDOException $e) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 2. Adjust Quantity (Stock In / Stock Out / Set)
    elseif ($action === 'adjust_quantity') {
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $adj_type = isset($_POST['adjustment_type']) ? $_POST['adjustment_type'] : 'add';
        $adj_conditions = get_stock_conditions();
        $adj_column = isset($_POST['stock_condition']) && isset($adj_conditions[$_POST['stock_condition']])
            ? $_POST['stock_condition'] : 'qty_good';
        $amount = isset($_POST['amount']) ? intval($_POST['amount']) : 0;
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : 'Manual Adjustment';
        $custom_notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
        $client_name = isset($_POST['client_name']) ? trim($_POST['client_name']) : '';

        if ($item_id <= 0) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode("Invalid item selected."));
            exit;
        }

        try {
            $stmt_cur = $pdo->prepare("SELECT id, name, quantity, qty_good, qty_defective, qty_damaged, qty_diagnostic FROM support_inventory_items WHERE id = :id LIMIT 1");
            $stmt_cur->execute(array(':id' => $item_id));
            $item_data = $stmt_cur->fetch();

            if (!$item_data) {
                header("Location: inventory.php?msg=error&err_msg=" . urlencode("Item not found."));
                exit;
            }

            $prev_qty = intval($item_data['quantity']);
            $new_qty = $prev_qty;
            $qty_change = 0;
            $change_label = 'Manual Adjustment';

            // Adjustments apply to one condition bucket; the total is derived from them
            $prev_bucket = intval($item_data[$adj_column]);

            if ($adj_type === 'add') {
                $qty_change = max(1, $amount);
                $new_bucket = $prev_bucket + $qty_change;
                $change_label = 'Stock In';
            } elseif ($adj_type === 'subtract') {
                $qty_change = -max(1, $amount);
                $new_bucket = max(0, $prev_bucket + $qty_change);
                $qty_change = $new_bucket - $prev_bucket;
                $change_label = 'Stock Out';
            } elseif ($adj_type === 'set') {
                $new_bucket = max(0, $amount);
                $qty_change = $new_bucket - $prev_bucket;
                $change_label = 'Stock Set';
            } else {
                $new_bucket = $prev_bucket;
                $qty_change = 0;
                $change_label = 'Stock Set';
            }

            $now = date('Y-m-d H:i:s');
            $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET `" . $adj_column . "` = :bucket, updated_at = :now WHERE id = :id");
            $stmt_up->execute(array(':bucket' => $new_bucket, ':now' => $now, ':id' => $item_id));
            $new_qty = resync_item_total_quantity($pdo, $item_id);

            $short_labels = get_stock_condition_short_labels();
            $full_notes = '[' . $short_labels[$adj_column] . '] ' . $reason;
            if (!empty($custom_notes)) {
                $full_notes .= ' - ' . $custom_notes;
            }

            $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
                (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, accountnum, client_name, notes, created_at) 
                VALUES (:item_id, :tech, :type, :change, :prev, :new, :acct, :client, :notes, :now)");
            $stmt_log->execute(array(
                ':item_id' => $item_id,
                ':tech' => $tech_name,
                ':type' => $change_label,
                ':change' => $qty_change,
                ':prev' => $prev_qty,
                ':new' => $new_qty,
                ':acct' => $accountnum,
                ':client' => $client_name,
                ':notes' => $full_notes,
                ':now' => $now
            ));

            header("Location: inventory.php?msg=quantity_adjusted");
            exit;
        } catch (PDOException $e) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 3. Edit Item Details
    elseif ($action === 'edit_item') {
        // Editing rewrites pricing and the per-condition stock split, so the
        // Level 2 Security Access Code is verified again before anything is written.
        $code_error = verify_inventory_action_code($action_code, 'update this hardware item');
        if ($code_error !== '') {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode($code_error));
            exit;
        }

        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $item_code = isset($_POST['item_code']) ? trim($_POST['item_code']) : '';
        $min_threshold = isset($_POST['min_threshold']) ? intval($_POST['min_threshold']) : 5;
        $cost_price = isset($_POST['cost_price']) ? floatval($_POST['cost_price']) : 0.00;
        $selling_price = isset($_POST['selling_price']) ? floatval($_POST['selling_price']) : (isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : 0.00);
        if ($cost_price < 0) $cost_price = 0.00;
        if ($selling_price < 0) $selling_price = 0.00;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';

        // Per-condition stock is editable here; the total is derived from these
        $qty_good       = isset($_POST['qty_good']) ? max(0, intval($_POST['qty_good'])) : 0;
        $qty_defective  = isset($_POST['qty_defective']) ? max(0, intval($_POST['qty_defective'])) : 0;
        $qty_damaged    = isset($_POST['qty_damaged']) ? max(0, intval($_POST['qty_damaged'])) : 0;
        $qty_diagnostic = isset($_POST['qty_diagnostic']) ? max(0, intval($_POST['qty_diagnostic'])) : 0;

        if ($item_id <= 0 || empty($name)) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode("Invalid item parameters."));
            exit;
        }

        try {
            $now = date('Y-m-d H:i:s');

            // Capture the current condition split so any stock change can be audited
            $stmt_before = $pdo->prepare("SELECT quantity, qty_good, qty_defective, qty_damaged, qty_diagnostic FROM support_inventory_items WHERE id = :id LIMIT 1");
            $stmt_before->execute(array(':id' => $item_id));
            $before_row = $stmt_before->fetch();

            if (!$before_row) {
                header("Location: inventory.php?msg=error&err_msg=" . urlencode("Item not found."));
                exit;
            }

            $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET 
                item_code = :code, name = :name, min_threshold = :min, 
                cost_price = :cost, selling_price = :selling, unit_price = :selling,
                description = :description, 
                qty_good = :q_good, qty_defective = :q_def, qty_damaged = :q_dam, qty_diagnostic = :q_diag,
                status = :status, updated_at = :now WHERE id = :id");
            $stmt_up->execute(array(
                ':code' => $item_code,
                ':name' => $name,
                ':min' => $min_threshold,
                ':cost' => $cost_price,
                ':selling' => $selling_price,
                ':description' => $description,
                ':q_good' => $qty_good,
                ':q_def' => $qty_defective,
                ':q_dam' => $qty_damaged,
                ':q_diag' => $qty_diagnostic,
                ':status' => $status,
                ':now' => $now,
                ':id' => $item_id
            ));

            $prev_total = intval($before_row['quantity']);
            $new_total = resync_item_total_quantity($pdo, $item_id);

            // Record a movement entry when the edit actually moved stock, so the
            // audit log stays complete rather than only tracking the adjust modal.
            $changed_parts = array();
            $short_labels = get_stock_condition_short_labels();
            $submitted = array(
                'qty_good' => $qty_good,
                'qty_defective' => $qty_defective,
                'qty_damaged' => $qty_damaged,
                'qty_diagnostic' => $qty_diagnostic
            );
            foreach ($short_labels as $col => $label) {
                $was = intval($before_row[$col]);
                $is = intval($submitted[$col]);
                if ($was !== $is) {
                    $changed_parts[] = $label . ': ' . $was . ' -> ' . $is;
                }
            }

            if (!empty($changed_parts)) {
                $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
                    (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, notes, created_at) 
                    VALUES (:item_id, :tech, 'Stock Set (Item Edit)', :change, :prev, :new, :notes, :now)");
                $stmt_log->execute(array(
                    ':item_id' => $item_id,
                    ':tech' => $tech_name,
                    ':change' => ($new_total - $prev_total),
                    ':prev' => $prev_total,
                    ':new' => $new_total,
                    ':notes' => 'Condition stock edited - ' . implode(' | ', $changed_parts),
                    ':now' => $now
                ));
            }

            header("Location: inventory.php?msg=item_updated");
            exit;
        } catch (PDOException $e) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 4. Enable / Disable Item (client visibility switch - preferred over delete)
    elseif ($action === 'set_item_status') {
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $new_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
        $status_reason = isset($_POST['status_reason']) ? trim($_POST['status_reason']) : '';

        $allowed_statuses = array('Active', 'Inactive', 'Discontinued');
        if ($item_id <= 0 || !in_array($new_status, $allowed_statuses, true)) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode(
                "Invalid request: choose a valid hardware item and availability status."));
            exit;
        }

        // Hiding an item from clients is an availability decision, so it is held
        // to the same Security Access Code check as editing the item.
        $code_error = verify_inventory_action_code($action_code, ($new_status === 'Active' ? 'enable this hardware item' : 'disable this hardware item'));
        if ($code_error !== '') {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode($code_error));
            exit;
        }

        try {
            $stmt_cur = $pdo->prepare("SELECT id, name, status, quantity FROM support_inventory_items WHERE id = :id LIMIT 1");
            $stmt_cur->execute(array(':id' => $item_id));
            $status_item = $stmt_cur->fetch();

            if (!$status_item) {
                header("Location: inventory.php?msg=error&err_msg=" . urlencode("Hardware item not found in inventory."));
                exit;
            }

            $old_status = $status_item['status'];
            if ($old_status === $new_status) {
                header("Location: inventory.php?msg=error&err_msg=" . urlencode(
                    $status_item['name'] . " is already set to " . $new_status . "."));
                exit;
            }

            $now = date('Y-m-d H:i:s');
            $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET status = :status, updated_at = :now WHERE id = :id");
            $stmt_up->execute(array(':status' => $new_status, ':now' => $now, ':id' => $item_id));

            // Stock is untouched, but the availability switch still belongs in the
            // audit log so the catalog change can be traced back to a technician.
            $cur_qty = intval($status_item['quantity']);
            $log_note = 'Client visibility changed: ' . $old_status . ' -> ' . $new_status .
                ($new_status === 'Active'
                    ? '. Item is visible again in the client ordering catalog.'
                    : '. Item is hidden from the client ordering catalog.');
            if ($status_reason !== '') {
                $log_note .= ' Reason: ' . $status_reason;
            }

            $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs
                (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, notes, created_at)
                VALUES (:item_id, :tech, :ctype, 0, :prev, :new_q, :notes, :now)");
            $stmt_log->execute(array(
                ':item_id' => $item_id,
                ':tech' => $tech_name,
                ':ctype' => ($new_status === 'Active') ? 'Item Enabled' : 'Item Disabled',
                ':prev' => $cur_qty,
                ':new_q' => $cur_qty,
                ':notes' => $log_note,
                ':now' => $now
            ));

            $redirect_msg = ($new_status === 'Active') ? 'item_enabled' : 'item_disabled';
            header("Location: inventory.php?msg=" . $redirect_msg . "&item=" . urlencode($status_item['name']));
            exit;
        } catch (PDOException $e) {
            header("Location: inventory.php?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 5. Delete Item
    elseif ($action === 'delete_item') {
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        if ($item_id > 0) {
            try {
                $stmt_del = $pdo->prepare("DELETE FROM support_inventory_items WHERE id = :id");
                $stmt_del->execute(array(':id' => $item_id));

                $stmt_del_logs = $pdo->prepare("DELETE FROM support_inventory_logs WHERE item_id = :id");
                $stmt_del_logs->execute(array(':id' => $item_id));

                header("Location: inventory.php?msg=item_deleted");
                exit;
            } catch (PDOException $e) {
                header("Location: inventory.php?msg=error&err_msg=" . urlencode($e->getMessage()));
                exit;
            }
        }
    }

    // 6. Sync Portal Hardware
    elseif ($action === 'sync_portal_hardware') {
        $synced = seed_portal_hardware_inventory();
        header("Location: inventory.php?msg=synced&count=" . $synced);
        exit;
    }
}

// ----------------------------------------------------
// Filters and Query Setup
// ----------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$stock_status = isset($_GET['stock_status']) ? trim($_GET['stock_status']) : '';
$sort_by = isset($_GET['sort']) ? trim($_GET['sort']) : 'name_asc';

$where_clauses = array("1=1");
$params = array();

if (!empty($search)) {
    $where_clauses[] = "(name LIKE :s1 OR item_code LIKE :s2 OR description LIKE :s3)";
    $params[':s1'] = "%" . $search . "%";
    $params[':s2'] = "%" . $search . "%";
    $params[':s3'] = "%" . $search . "%";
}

// Stock health is judged on deployable (good) stock, not the grand total -
// a pile of defective units should never read as "in stock".
if ($stock_status === 'low') {
    $where_clauses[] = "qty_good <= min_threshold AND qty_good > 0";
} elseif ($stock_status === 'out') {
    $where_clauses[] = "qty_good = 0";
} elseif ($stock_status === 'in_stock') {
    $where_clauses[] = "qty_good > min_threshold";
}

$order_by = "name ASC";
if ($sort_by === 'qty_asc') {
    $order_by = "qty_good ASC";
} elseif ($sort_by === 'qty_desc') {
    $order_by = "qty_good DESC";
} elseif ($sort_by === 'price_desc') {
    $order_by = "IF(selling_price > 0, selling_price, unit_price) DESC";
} elseif ($sort_by === 'price_asc') {
    $order_by = "IF(selling_price > 0, selling_price, unit_price) ASC";
} elseif ($sort_by === 'cost_desc') {
    $order_by = "cost_price DESC";
} elseif ($sort_by === 'cost_asc') {
    $order_by = "cost_price ASC";
} elseif ($sort_by === 'newest') {
    $order_by = "created_at DESC";
}

$where_sql = implode(" AND ", $where_clauses);
$stmt_items = $pdo->prepare("SELECT * FROM support_inventory_items WHERE $where_sql ORDER BY $order_by");
$stmt_items->execute($params);
$items = $stmt_items->fetchAll();

// KPI Stats
$total_skus = intval($pdo->query("SELECT COUNT(*) FROM support_inventory_items")->fetchColumn());
$total_units = intval($pdo->query("SELECT SUM(quantity) FROM support_inventory_items")->fetchColumn());
$low_stock_count = intval($pdo->query("SELECT COUNT(*) FROM support_inventory_items WHERE qty_good <= min_threshold AND qty_good > 0")->fetchColumn());
$out_of_stock_count = intval($pdo->query("SELECT COUNT(*) FROM support_inventory_items WHERE qty_good = 0")->fetchColumn());

// Units held per condition, for the header KPI strip
$cond_totals = array();
foreach (array_keys(get_stock_conditions()) as $cond_key) {
    $cond_totals[$cond_key] = intval($pdo->query("SELECT SUM(`" . $cond_key . "`) FROM support_inventory_items")->fetchColumn());
}

// Valuation counts deployable (good) stock only - scrap and defective units
// are not sellable and would otherwise inflate the figures.
$total_cost_row = $pdo->query("SELECT SUM(qty_good * cost_price) FROM support_inventory_items")->fetchColumn();
$total_cost_valuation = floatval($total_cost_row ? $total_cost_row : 0);

$total_val_row = $pdo->query("SELECT SUM(qty_good * IF(selling_price > 0, selling_price, unit_price)) FROM support_inventory_items")->fetchColumn();
$total_selling_valuation = floatval($total_val_row ? $total_val_row : 0);

// Estimated Margin / Profit
$total_inventory_profit = max(0, $total_selling_valuation - $total_cost_valuation);

// Fetch all inventory items for quick dropdown selection in Pull Out Modal
$stmt_all_inv = $pdo->query("SELECT id, item_code, name, quantity, qty_good, qty_defective, qty_damaged, qty_diagnostic, min_threshold, cost_price, selling_price, unit_price, status FROM support_inventory_items WHERE status = 'Active' ORDER BY name ASC");
$all_inventory_items = $stmt_all_inv ? $stmt_all_inv->fetchAll() : array();

// Hardware already recorded against each client in accounts.php (client_assets),
// used to narrow the Pull Out item list when pulling FROM a client.
$client_hardware_map = array();
$stmt_ca = $pdo->query("SELECT id, accountnum, item_id, item_code, name, serial_number, quantity, unit_price 
    FROM client_assets WHERE asset_type = 'Hardware' AND item_id IS NOT NULL ORDER BY name ASC");
if ($stmt_ca) {
    foreach ($stmt_ca->fetchAll() as $ca_row) {
        $ca_acct = $ca_row['accountnum'];
        if (!isset($client_hardware_map[$ca_acct])) {
            $client_hardware_map[$ca_acct] = array();
        }
        $client_hardware_map[$ca_acct][] = array(
            'asset_id' => intval($ca_row['id']),
            'item_id' => intval($ca_row['item_id']),
            'item_code' => $ca_row['item_code'],
            'name' => $ca_row['name'],
            'serial_number' => $ca_row['serial_number'],
            'quantity' => intval($ca_row['quantity']),
            'unit_price' => floatval($ca_row['unit_price'])
        );
    }
}

// Fetch Clients for Tagging in Pull-outs and Adjustments
$stmt_clients = $pdo->query("SELECT accountnum, tradename, clientname, address FROM bucket_client ORDER BY tradename ASC");
$clients_list = $stmt_clients ? $stmt_clients->fetchAll() : array();

// Fetch Recent 25 Inventory Movement Logs
$stmt_recent_logs = $pdo->query("SELECT l.*, i.name as item_name, i.item_code, i.image_path 
    FROM support_inventory_logs l 
    LEFT JOIN support_inventory_items i ON l.item_id = i.id 
    ORDER BY l.created_at DESC LIMIT 25");
$recent_logs = $stmt_recent_logs->fetchAll();

$portal_catalog = get_portal_hardware_catalog();

$preset_pullout_client = isset($_GET['pullout_client']) ? sanitize($_GET['pullout_client']) : '';
$preset_pullout_item = isset($_GET['pullout_item']) ? intval($_GET['pullout_item']) : 0;

$my_tier = get_logged_tech_access_tier();

$active_page = 'inventory';
$page_title = 'Hardware Inventory Hub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hardware Inventory Hub - RNZ Admin</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#FFF5ED',
                            100: '#FFE8D5',
                            500: '#FA5915',
                            600: '#EB3E0B',
                            700: '#C32C0B',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased min-h-screen">

<div class="flex min-h-screen">
    <!-- Admin Sidebar Navigation -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Top Admin Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-6 sm:p-8 space-y-8 max-w-7xl w-full mx-auto">

            <!-- Toast / Status Alert Banner -->
            <?php if (!empty($msg_text)): ?>
                <div class="p-4 rounded-2xl flex items-center justify-between border shadow-sm transition-all <?php echo ($msg_type === 'error') ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800'; ?>">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 rounded-xl flex items-center justify-center font-bold <?php echo ($msg_type === 'error') ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600'; ?>">
                            <?php if ($msg_type === 'error'): ?>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?php else: ?>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs sm:text-sm font-semibold"><?php echo sanitize($msg_text); ?></p>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Page Title & Header Actions -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <span>Hardware Inventory</span>
                        <span class="text-xs font-mono font-bold px-3 py-1 bg-slate-200 text-slate-700 rounded-full">
                            <?php echo number_format($total_skus); ?> Items
                        </span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">
                        Manage thermal printers, scanners, monitors, cash drawers, and POS peripherals.
                    </p>
                </div>

                <!-- Top Quick Action Buttons -->
                <div class="flex items-center flex-wrap gap-2.5">
                    <!-- Sync / Seed Catalog Button (Tier 2 & 3 only) -->
                    <?php if ($my_tier >= 2): ?>
                    <form method="POST" action="" onsubmit="return confirm('Sync with Client Portal Hardware catalog to ensure all 15 core devices are populated?');" class="inline">
                        <input type="hidden" name="action" value="sync_portal_hardware">
                        <button type="submit" class="bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs sm:text-sm px-4 py-2.5 rounded-2xl border border-slate-200 shadow-sm flex items-center space-x-2 transition-all hover:border-slate-300">
                            <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            <span>Sync Portal Hardware</span>
                        </button>
                    </form>
                    <?php endif; ?>

                    <!-- View Movement Log Button (All tiers including Level 1 can view history) -->
                    <button onclick="openMovementLogModal()" class="bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs sm:text-sm px-4 py-2.5 rounded-2xl border border-slate-200 shadow-sm flex items-center space-x-2 transition-all hover:border-slate-300">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Stock Movement History</span>
                    </button>

                    <!-- Pull Out Items Button (Tier 2 & 3 only) -->
                    <?php if ($my_tier >= 2): ?>
                    <button onclick="openPullOutModal()" class="bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-bold text-xs sm:text-sm px-4 py-2.5 rounded-2xl shadow-sm shadow-amber-500/30 flex items-center space-x-2 transition-all active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span>Pull Out Items</span>
                    </button>
                    <?php endif; ?>

                    <!-- Add New Item Button (Tier 2 & 3 only) -->
                    <?php if ($my_tier >= 2): ?>
                    <button onclick="openAddItemModal()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-5 py-2.5 rounded-2xl shadow-sm shadow-[#EB3E0B]/30 flex items-center space-x-2 transition-all active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                        </svg>
                        <span>Add New Item</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPI Metric Cards Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                <!-- Total In Stock Units -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Total Units in Stock</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($total_units); ?></h3>
                        <p class="text-[11px] text-slate-400">Across <?php echo number_format($total_skus); ?> catalog items</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                </div>

                <!-- Materials Cost Valuation -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Materials Cost Value</span>
                        <h3 class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono truncate">₱<?php echo number_format($total_cost_valuation, 2); ?></h3>
                        <p class="text-[11px] text-slate-400">Total capital purchase cost</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                </div>

                <!-- Selling Valuation -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Selling / Retail Value</span>
                        <h3 class="text-2xl sm:text-3xl font-extrabold text-emerald-700 font-mono truncate">₱<?php echo number_format($total_selling_valuation, 2); ?></h3>
                        <p class="text-[11px] text-slate-400">Total client retail worth</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>

                <!-- Estimated Gross Profit / Margin -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-indigo-600 uppercase tracking-wider">Est. Gross Margin</span>
                        <h3 class="text-2xl sm:text-3xl font-extrabold text-indigo-700 font-mono truncate">+₱<?php echo number_format($total_inventory_profit, 2); ?></h3>
                        <p class="text-[11px] text-slate-400">
                            <?php 
                                $overall_margin = ($total_cost_valuation > 0) ? round(($total_inventory_profit / $total_cost_valuation) * 100, 1) : 0;
                                echo $overall_margin . '% markup on inventory';
                            ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filters & Search Bar -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 sm:p-6 space-y-4">
                <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                    <!-- Search Input -->
                    <div class="sm:col-span-5 relative">
                        <input type="text" name="search" value="<?php echo sanitize($search); ?>" placeholder="Search by hardware name, SKU, or description..." class="w-full bg-slate-50 text-slate-800 text-xs pl-10 pr-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all placeholder-slate-400">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <!-- Stock Status Filter -->
                    <div class="sm:col-span-3">
                        <select name="stock_status" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all font-semibold">
                            <option value="" <?php echo empty($stock_status) ? 'selected' : ''; ?>>All Stock Levels</option>
                            <option value="in_stock" <?php echo ($stock_status === 'in_stock') ? 'selected' : ''; ?>>In Stock (Sufficient)</option>
                            <option value="low" <?php echo ($stock_status === 'low') ? 'selected' : ''; ?>>Low Stock Warning (<?php echo $low_stock_count; ?>)</option>
                            <option value="out" <?php echo ($stock_status === 'out') ? 'selected' : ''; ?>>Out of Stock (<?php echo $out_of_stock_count; ?>)</option>
                        </select>
                    </div>

                    <!-- Sort Filter -->
                    <div class="sm:col-span-3">
                        <select name="sort" class="w-full bg-slate-50 text-slate-800 text-xs px-3.5 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all font-semibold">
                            <option value="name_asc" <?php if ($sort_by === 'name_asc') echo 'selected'; ?>>Name (A - Z)</option>
                            <option value="qty_desc" <?php if ($sort_by === 'qty_desc') echo 'selected'; ?>>Highest Quantity</option>
                            <option value="qty_asc" <?php if ($sort_by === 'qty_asc') echo 'selected'; ?>>Lowest Quantity</option>
                            <option value="price_desc" <?php if ($sort_by === 'price_desc') echo 'selected'; ?>>Highest Selling Price</option>
                            <option value="price_asc" <?php if ($sort_by === 'price_asc') echo 'selected'; ?>>Lowest Selling Price</option>
                            <option value="cost_desc" <?php if ($sort_by === 'cost_desc') echo 'selected'; ?>>Highest Materials Cost</option>
                            <option value="cost_asc" <?php if ($sort_by === 'cost_asc') echo 'selected'; ?>>Lowest Materials Cost</option>
                            <option value="newest" <?php if ($sort_by === 'newest') echo 'selected'; ?>>Newest Added</option>
                        </select>
                    </div>

                    <!-- Sort and Filter Submit Button -->
                    <div class="sm:col-span-1 flex items-center gap-1.5">
                        <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs py-3 px-3 rounded-2xl transition-all shadow-sm">
                            Go
                        </button>
                        <?php if (!empty($search) || !empty($stock_status) || $sort_by !== 'name_asc'): ?>
                            <a href="inventory.php" class="p-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-2xl text-xs font-bold transition-all flex items-center justify-center shrink-0" title="Reset Filters">
                                &times;
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Inventory Items Table & Cards -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden space-y-4">
                <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-900">Inventory Items List</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Manage quantities, thresholds, pricing and stock adjustments.</p>
                    </div>
                    <div class="text-xs text-slate-500 font-medium">
                        Showing <strong class="text-slate-900"><?php echo count($items); ?></strong> item(s)
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3.5 px-6">Hardware Item & Code</th>
                                <th class="py-3.5 px-6">Description / Specs</th>
                                <th class="py-3.5 px-6">Pricing & Margins</th>
                                <th class="py-3.5 px-6 text-center">Stock by Condition</th>
                                <th class="py-3.5 px-6 text-center">Quick Adjust</th>
                                <th class="py-3.5 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="6" class="py-12 text-center text-slate-400 space-y-3">
                                        <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        </div>
                                        <p class="font-bold text-slate-600">No hardware inventory items found.</p>
                                        <p class="text-xs">Try clearing search filters or click "Sync Portal Hardware" to import standard items.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $cond_labels = get_stock_condition_short_labels();
                                $cond_styles = array(
                                    'qty_good'       => 'text-emerald-700',
                                    'qty_defective'  => 'text-amber-700',
                                    'qty_damaged'    => 'text-rose-700',
                                    'qty_diagnostic' => 'text-slate-600'
                                );
                                ?>
                                <?php foreach ($items as $item): 
                                    $qty = intval($item['quantity']);
                                    $qty_good = intval($item['qty_good']);
                                    $min = intval($item['min_threshold']);
                                    $cost = isset($item['cost_price']) ? floatval($item['cost_price']) : 0.00;
                                    $selling = isset($item['selling_price']) && floatval($item['selling_price']) > 0 ? floatval($item['selling_price']) : floatval($item['unit_price']);
                                    $profit_unit = $selling - $cost;
                                    $margin_pct = ($cost > 0) ? round(($profit_unit / $cost) * 100, 1) : 0;
                                    
                                    // Status badge reflects deployable (good) stock only
                                    if ($qty_good == 0) {
                                        $stock_badge = 'bg-rose-100 text-rose-800 border-rose-300';
                                        $stock_label = 'No Good Stock';
                                    } elseif ($qty_good <= $min) {
                                        $stock_badge = 'bg-amber-100 text-amber-800 border-amber-300';
                                        $stock_label = 'Low Stock (' . $qty_good . ')';
                                    } else {
                                        $stock_badge = 'bg-emerald-100 text-emerald-800 border-emerald-300';
                                        $stock_label = 'In Stock';
                                    }
                                ?>
                                    <?php $is_disabled = ($item['status'] !== 'Active'); ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors <?php echo $is_disabled ? 'bg-slate-50/70 opacity-75' : ''; ?>">
                                        <!-- Item & Code -->
                                        <td class="py-4 px-6">
                                            <div class="min-w-0">
                                                <h4 class="font-extrabold text-slate-900 text-sm truncate max-w-xs" title="<?php echo sanitize($item['name']); ?>">
                                                    <?php echo sanitize($item['name']); ?>
                                                </h4>
                                                <div class="flex items-center space-x-2 mt-0.5">
                                                    <span class="font-mono font-bold text-xs text-[#EB3E0B]">
                                                        <?php echo sanitize($item['item_code']); ?>
                                                    </span>
                                                    <?php if ($is_disabled): ?>
                                                        <span class="inline-flex items-center text-[10px] px-1.5 py-0.5 rounded bg-slate-700 text-white font-bold" title="Disabled items stay in inventory but are hidden from the client ordering catalog">
                                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                                            <?php echo sanitize($item['status']); ?> - Hidden from clients
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Description / Remarks -->
                                        <td class="py-4 px-6 text-slate-600 text-xs max-w-xs">
                                            <?php if (!empty($item['description'])): ?>
                                                <p class="leading-relaxed text-slate-700 line-clamp-2" title="<?php echo sanitize($item['description']); ?>">
                                                    <?php echo sanitize($item['description']); ?>
                                                </p>
                                            <?php else: ?>
                                                <span class="text-slate-400 italic text-[11px]">No remarks</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Pricing & Margins -->
                                        <td class="py-4 px-6 min-w-[170px]">
                                            <div class="space-y-1">
                                                <div class="flex items-center justify-between text-xs gap-2">
                                                    <span class="text-slate-400 font-medium">Cost:</span>
                                                    <span class="font-mono font-bold text-slate-800">₱<?php echo number_format($cost, 2); ?></span>
                                                </div>
                                                <div class="flex items-center justify-between text-xs gap-2">
                                                    <span class="text-slate-400 font-medium">Selling:</span>
                                                    <span class="font-mono font-extrabold text-emerald-600">₱<?php echo number_format($selling, 2); ?></span>
                                                </div>
                                                <?php if ($selling > 0 && $cost > 0): ?>
                                                    <div class="flex items-center justify-between text-[10px] border-t border-slate-100 pt-0.5 font-mono">
                                                        <span class="text-slate-400">Margin:</span>
                                                        <span class="<?php echo $profit_unit >= 0 ? 'text-emerald-700 font-bold' : 'text-rose-600 font-bold'; ?>">
                                                            <?php echo ($profit_unit >= 0 ? '+' : '') . '₱' . number_format($profit_unit, 2); ?> (<?php echo $margin_pct; ?>%)
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Stock split by condition; total is the sum of the buckets -->
                                        <td class="py-4 px-6">
                                            <div class="flex flex-col items-center gap-1">
                                                <div class="flex items-baseline gap-1.5">
                                                    <span class="text-base font-extrabold font-mono text-emerald-700"><?php echo number_format($qty_good); ?></span>
                                                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">good</span>
                                                </div>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $stock_badge; ?>">
                                                    <?php echo $stock_label; ?>
                                                </span>

                                                <div class="w-full max-w-[150px] mt-1 pt-1.5 border-t border-slate-100 space-y-0.5">
                                                    <?php foreach ($cond_labels as $cond_key => $cond_label): ?>
                                                        <?php $cond_val = intval($item[$cond_key]); ?>
                                                        <div class="flex items-center justify-between text-[10px] leading-tight">
                                                            <span class="<?php echo ($cond_val > 0) ? $cond_styles[$cond_key] . ' font-bold' : 'text-slate-300'; ?>"><?php echo $cond_label; ?></span>
                                                            <span class="font-mono <?php echo ($cond_val > 0) ? 'font-bold text-slate-800' : 'text-slate-300'; ?>"><?php echo $cond_val; ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <div class="flex items-center justify-between text-[10px] leading-tight pt-1 mt-0.5 border-t border-slate-100">
                                                        <span class="text-slate-500 font-bold uppercase tracking-wider">Total</span>
                                                        <span class="font-mono font-extrabold text-slate-900"><?php echo number_format($qty); ?></span>
                                                    </div>
                                                </div>

                                                <span class="text-[10px] text-slate-400 font-mono">Min: <?php echo $min; ?></span>
                                            </div>
                                        </td>

                                        <!-- Quick Adjust (+1 / -1 buttons) -->
                                        <td class="py-4 px-6 text-center">
                                            <?php if ($my_tier >= 2): ?>
                                                <div class="inline-flex items-center space-x-1.5">
                                                    <!-- Quick -1 -->
                                                    <form method="POST" action="" class="inline">
                                                        <input type="hidden" name="action" value="adjust_quantity">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="adjustment_type" value="subtract">
                                                        <input type="hidden" name="amount" value="1">
                                                        <input type="hidden" name="reason" value="Quick -1 Stock Out">
                                                        <button type="submit" <?php echo ($qty_good <= 0) ? 'disabled' : ''; ?> class="w-7 h-7 rounded-xl bg-slate-100 hover:bg-rose-100 text-slate-600 hover:text-rose-700 font-bold flex items-center justify-center transition-colors disabled:opacity-40" title="Quick Subtract 1 (Good stock)">
                                                            -
                                                        </button>
                                                    </form>

                                                    <!-- Open Detailed Adjust Modal -->
                                                    <button onclick='openAdjustModal(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)' class="px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-[#EB3E0B] text-slate-700 hover:text-white font-bold text-[11px] transition-colors" title="Adjust Stock Quantity">
                                                        Adjust
                                                    </button>

                                                    <!-- Open Pull Out Modal for this item -->
                                                    <button onclick='openPullOutModalForItem(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)' class="px-2.5 py-1 rounded-xl bg-amber-50 hover:bg-amber-500 text-amber-800 hover:text-white border border-amber-200 font-bold text-[11px] transition-colors" title="Pull Out this Hardware Item">
                                                        Pull Out
                                                    </button>

                                                    <!-- Quick +1 -->
                                                    <form method="POST" action="" class="inline">
                                                        <input type="hidden" name="action" value="adjust_quantity">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="adjustment_type" value="add">
                                                        <input type="hidden" name="amount" value="1">
                                                        <input type="hidden" name="reason" value="Quick +1 Stock In">
                                                        <button type="submit" class="w-7 h-7 rounded-xl bg-slate-100 hover:bg-emerald-100 text-slate-600 hover:text-emerald-700 font-bold flex items-center justify-center transition-colors" title="Quick Add 1">
                                                            +
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs font-semibold text-slate-400">View Only</span>
                                            <?php endif; ?>
                                         </td>

                                         <!-- Edit / Actions -->
                                         <td class="py-4 px-6 text-right">
                                             <?php if ($my_tier >= 2): ?>
                                                 <div class="flex items-center justify-end space-x-1.5">
                                                     <!-- Edit Button -->
                                                     <button onclick='openEditModal(<?php echo json_encode($item); ?>)' class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 flex items-center justify-center transition-colors" title="Edit Item">
                                                         <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                     </button>

                                                     <!-- Disable / Enable Button (client catalog visibility) -->
                                                     <button onclick='openStatusModal(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)'
                                                             class="w-8 h-8 rounded-xl flex items-center justify-center transition-colors <?php echo $is_disabled ? 'bg-emerald-50 hover:bg-emerald-500 text-emerald-700 hover:text-white' : 'bg-slate-100 hover:bg-amber-100 text-slate-500 hover:text-amber-700'; ?>"
                                                             title="<?php echo $is_disabled ? 'Enable this item so clients can order it again' : 'Disable this item (hide it from the client ordering catalog)'; ?>">
                                                         <?php if ($is_disabled): ?>
                                                             <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                         <?php else: ?>
                                                             <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                                         <?php endif; ?>
                                                     </button>

                                                     <!-- Delete Button -->
                                                     <form method="POST" action="" onsubmit="return confirm('Are you sure you want to permanently DELETE this inventory item (<?php echo addslashes($item['name']); ?>)?\n\nDeleting also removes its movement history. If you only want to hide it from clients, cancel and use the Disable button instead.');" class="inline">
                                                         <input type="hidden" name="action" value="delete_item">
                                                         <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                         <button type="submit" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-rose-100 text-slate-500 hover:text-rose-600 flex items-center justify-center transition-colors" title="Delete Item">
                                                             <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                         </button>
                                                     </form>
                                                 </div>
                                             <?php else: ?>
                                                 <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500">🔒 View Only</span>
                                             <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: PULL OUT HARDWARE ITEM -->
<!-- ========================================================================= -->
<div id="pullOutModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-xl w-full p-6 sm:p-8 space-y-6 max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center font-bold shadow-md shadow-amber-500/25">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Pull Out Hardware Item</h3>
                    <p class="text-xs text-slate-500">Record hardware retrieval or deployment & sync with client service history.</p>
                </div>
            </div>
            <button type="button" onclick="closePullOutModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="pull_out_item">

            <!-- 1. Pull Out Direction
                 Releasing stock to a client is done from the client account page
                 (Software & Hardware > Add Hardware), which deducts inventory and
                 raises the work order in one step. This modal only brings units back. -->
            <input type="hidden" name="pullout_direction" value="from_client">
            <div class="space-y-1.5">
                <label class="text-xs font-bold text-slate-700">Pull-Out Type / Movement Direction</label>
                <div class="border-2 border-amber-500 bg-amber-50/50 rounded-2xl p-3 flex items-center space-x-3">
                    <span class="text-lg">🔄</span>
                    <div>
                        <span class="block text-xs font-bold text-slate-900">From Client to Office</span>
                        <span class="block text-[10px] text-slate-500">Defective, repair, warranty pull-out</span>
                    </div>
                </div>
            </div>

            <!-- 2. Client Selection -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">To / From Which Client Account <span class="text-[#EB3E0B]">*</span></label>
                <select name="accountnum" id="pullout_accountnum" required onchange="updatePullOutClient(this)" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-semibold">
                    <option value="">-- Choose Client Account --</option>
                    <?php foreach ($clients_list as $c): 
                        $display_title = !empty($c['tradename']) ? $c['tradename'] : $c['clientname'];
                    ?>
                        <option value="<?php echo sanitize($c['accountnum']); ?>" 
                                data-tradename="<?php echo sanitize($display_title); ?>"
                                data-address="<?php echo sanitize(isset($c['address']) ? $c['address'] : ''); ?>">
                            <?php echo sanitize($display_title . ' (Account #' . $c['accountnum'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="client_name" id="pullout_client_name" value="">
                <input type="hidden" name="address" id="pullout_client_address" value="">
            </div>

            <!-- 3. Hardware Item Selector (list depends on direction + client) -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700"><span id="pullout_item_label">Hardware Item</span> <span class="text-[#EB3E0B]">*</span></label>
                <select name="item_id" id="pullout_item_id" required onchange="updatePullOutItem(this)" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-semibold">
                    <option value="">-- Choose a client account first --</option>
                </select>
                <p id="pullout_item_hint" class="text-[10px] text-slate-500 pt-0.5">Choose a client account first &mdash; the list will show only the hardware recorded for them.</p>
                <div id="pullout_item_info" class="hidden text-[11px] font-mono text-slate-500 pt-1 flex flex-wrap items-center justify-between gap-1">
                    <span>Selected: <strong id="pullout_item_name_text" class="text-slate-800 font-sans"></strong></span>
                    <span id="pullout_item_pricing_text" class="text-slate-600"></span>
                    <span>Good stock: <strong id="pullout_item_qty_text" class="text-amber-700"></strong> units</span>
                </div>
            </div>

            <!-- 4. Quantity and Serial Number Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Quantity <span class="text-[#EB3E0B]">*</span></label>
                    <input type="number" name="amount" id="pullout_amount" min="1" value="1" required class="w-full bg-slate-50 text-slate-800 text-sm font-extrabold px-4 py-2.5 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-mono">
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Serial Number / Tracking #</label>
                    <input type="text" name="serial_number" placeholder="e.g., SN-99482104" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-2.5 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-mono">
                </div>
            </div>

            <!-- 5. Reason for Pull-Out -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Reason for Pull-Out <span class="text-[#EB3E0B]">*</span></label>
                <select name="reason" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-medium">
                    <option value="Defective Unit / Pull-out for Diagnostics & Repair">Defective Unit / Pull-out for Diagnostics & Repair</option>
                    <option value="Warranty Replacement / Unit Exchange">Warranty Replacement / Unit Exchange</option>
                    <option value="Hardware Upgrade / Migration to New POS Model">Hardware Upgrade / Migration to New POS Model</option>
                    <option value="Store Closure / Subscription Termination">Store Closure / Subscription Termination</option>
                    <option value="Retrieval of Demo / Backup / Loaner Unit">Retrieval of Demo / Backup / Loaner Unit</option>
                    <option value="Preventive Maintenance Bench Testing">Preventive Maintenance Bench Testing</option>
                    <option value="Customer Sale / Deployment Release">Customer Sale / Deployment Release</option>
                    <option value="Other">Other / Custom Reason</option>
                </select>
            </div>

            <!-- 6. Item Condition / Diagnostic Status -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Item Condition / Hardware Status</label>
                <select name="condition_status" id="pullout_condition_status" onchange="updateRestockTarget()" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-2.5 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-medium">
                    <option value="Defective / Needs Repair">Defective / Needs Repair</option>
                    <option value="Good / Functional (Working Unit)">Good / Functional (Working Unit)</option>
                    <option value="Damaged / Beyond Repair (Scrap)">Damaged / Beyond Repair (Scrap)</option>
                    <option value="For Diagnostic / Bench Testing">For Diagnostic / Bench Testing</option>
                </select>
            </div>

            <!-- 7. Restock Option (Only if from client) -->
            <div id="restock_option_box" class="p-3 bg-amber-50/70 border border-amber-200 rounded-2xl space-y-1">
                <p class="text-xs font-bold text-amber-900 flex items-start space-x-2">
                    <svg class="w-4 h-4 shrink-0 mt-0.5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>This unit will be added to <strong id="restock_target_label" class="underline">Good</strong> stock for <strong id="restock_item_label">this item</strong>.</span>
                </p>
                <p class="text-[10px] text-amber-700 ml-6">The Item Condition above decides which stock bucket it lands in. Change the condition to send it elsewhere.</p>
            </div>

            <!-- 8. Auto Sync to Client Service Notes -->
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-2xl space-y-1">
                <label class="flex items-center space-x-2.5 cursor-pointer text-xs font-bold text-slate-800">
                    <input type="checkbox" name="auto_technote" value="1" checked class="rounded text-[#EB3E0B] focus:ring-0">
                    <span>Automatically create a Technical Service Note in Client Account (<code class="text-[10px] font-mono text-[#EB3E0B]">bucket_technotes</code>)</span>
                </label>
                <p class="text-[10px] text-slate-500 ml-6">Automatically creates a full log in the client's history with reason, technician, and hardware specifications.</p>
            </div>

            <!-- 9. Additional Notes / Diagnostics Remarks -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Remarks / Diagnostic Notes / Ticket Reference</label>
                <textarea name="notes" rows="2" placeholder="e.g., Client reported paper feed jam; bringing unit to office for roller gear replacement. Ticket # RNZ-2026-0012" class="w-full bg-slate-50 text-slate-800 text-xs p-3 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none"></textarea>
            </div>

            <!-- Access Level Tier Banner & Input -->
            <?php 
                $my_tier = get_logged_tech_access_tier();
                if ($my_tier === 1): 
            ?>
                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot pull out items.</span>
                </div>
            <?php elseif ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                    <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Access Code Required (Level 2 Account)</span>
                    </label>
                    <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closePullOutModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <?php if ($my_tier === 1): ?>
                    <button type="button" disabled class="px-6 py-2.5 rounded-2xl bg-slate-300 text-slate-500 font-bold text-xs cursor-not-allowed">
                        🔒 View Only
                    </button>
                <?php else: ?>
                    <button type="submit" class="px-6 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold text-xs hover:from-amber-600 hover:to-amber-700 shadow-md shadow-amber-500/30 transition-all">
                        Confirm & Record Pull-Out
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADD NEW ITEM -->
<!-- ========================================================================= -->
<div id="addItemModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-xl w-full p-6 sm:p-8 space-y-6 max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Add New Inventory Item</h3>
                    <p class="text-xs text-slate-500">Register new hardware, peripherals, or spare parts.</p>
                </div>
            </div>
            <button type="button" onclick="closeAddItemModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="add_item">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Item Name -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Item Name <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="name" required placeholder="e.g., Thermal Receipt Printer 80mm" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Item SKU / Code -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">SKU / Item Code</label>
                    <input type="text" name="item_code" placeholder="e.g., HW-PRN-80 (Auto if blank)" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono uppercase">
                </div>

                <!-- Materials Cost Price -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700 flex items-center justify-between">
                        <span>Materials Cost Price (₱)</span>
                        <span class="text-[10px] text-slate-400 font-normal">Capital / Purchase</span>
                    </label>
                    <input type="number" name="cost_price" id="add_cost_price" step="0.01" min="0" value="0.00" oninput="calculateAddMargin()" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Selling Price -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700 flex items-center justify-between">
                        <span>Selling / SRP Price (₱)</span>
                        <span class="text-[10px] text-emerald-600 font-semibold">Client / Retail</span>
                    </label>
                    <input type="number" name="selling_price" id="add_selling_price" step="0.01" min="0" value="0.00" oninput="calculateAddMargin()" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Profit / Margin Indicator -->
                <div class="sm:col-span-2 p-2.5 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between text-xs">
                    <span class="text-slate-500 font-medium">Estimated Markup & Unit Margin:</span>
                    <span id="add_margin_preview" class="font-mono font-bold text-slate-700">₱0.00 (0.0%)</span>
                </div>

                <!-- Initial Quantity -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Initial Quantity</label>
                    <input type="number" name="quantity" min="0" value="10" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Minimum Threshold -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Min Alert Threshold</label>
                    <input type="number" name="min_threshold" min="0" value="3" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Description -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Description / Specs</label>
                    <textarea name="description" rows="2" placeholder="Hardware specifications, model, interface ports..." class="w-full bg-slate-50 text-slate-800 text-xs p-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none"></textarea>
                </div>
            </div>

            <!-- Access Level Tier Banner & Input -->
            <?php if ($my_tier === 1): ?>
                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot create new items.</span>
                </div>
            <?php elseif ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                    <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Access Code Required (Level 2 Account)</span>
                    </label>
                    <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeAddItemModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <?php if ($my_tier === 1): ?>
                    <button type="button" disabled class="px-6 py-2.5 rounded-2xl bg-slate-300 text-slate-500 font-bold text-xs cursor-not-allowed">
                        🔒 View Only
                    </button>
                <?php else: ?>
                    <button type="submit" class="px-6 py-2.5 rounded-2xl bg-[#EB3E0B] text-white font-bold text-xs hover:bg-[#C32C0B] shadow-sm shadow-[#EB3E0B]/30 transition-all">
                        Save Hardware Item
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADJUST QUANTITY -->
<!-- ========================================================================= -->
<div id="adjustModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-lg w-full p-6 sm:p-8 space-y-6 animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-blue-500 text-white flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Adjust Stock Quantity</h3>
                    <p class="text-xs text-slate-500" id="adj_item_name_display">Item Name</p>
                </div>
            </div>
            <button type="button" onclick="closeAdjustModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="adjust_quantity">
            <input type="hidden" name="item_id" id="adj_item_id" value="0">

            <!-- Current Stock Display Box -->
            <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 font-semibold">Total In Stock:</span>
                        <h4 class="text-2xl font-extrabold font-mono text-slate-900" id="adj_current_qty">0</h4>
                    </div>
                    <div class="text-right">
                        <span class="text-xs text-slate-500 font-semibold">SKU Code:</span>
                        <p class="text-xs font-mono font-bold text-[#EB3E0B]" id="adj_item_code">HW-000</p>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-2 pt-2 border-t border-slate-200 text-center">
                    <div>
                        <p class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider">Good</p>
                        <p class="text-sm font-extrabold font-mono text-slate-900" id="adj_qty_good">0</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-amber-700 uppercase tracking-wider">Defective</p>
                        <p class="text-sm font-extrabold font-mono text-slate-900" id="adj_qty_defective">0</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-rose-700 uppercase tracking-wider">Scrap</p>
                        <p class="text-sm font-extrabold font-mono text-slate-900" id="adj_qty_damaged">0</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">Diagnostic</p>
                        <p class="text-sm font-extrabold font-mono text-slate-900" id="adj_qty_diagnostic">0</p>
                    </div>
                </div>
            </div>

            <!-- Which condition bucket this adjustment applies to -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Apply To Condition <span class="text-[#EB3E0B]">*</span></label>
                <select name="stock_condition" id="adj_stock_condition" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-semibold">
                    <?php foreach (get_stock_conditions() as $cond_key => $cond_name): ?>
                        <option value="<?php echo sanitize($cond_key); ?>"<?php echo ($cond_key === 'qty_good') ? ' selected' : ''; ?>><?php echo sanitize($cond_name); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[10px] text-slate-500">The total stock is the sum of all four conditions and updates automatically.</p>
            </div>

            <!-- Adjustment Type -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Adjustment Type <span class="text-[#EB3E0B]">*</span></label>
                <div class="grid grid-cols-3 gap-2">
                    <label class="border border-slate-200 rounded-2xl p-3 flex items-center justify-center space-x-2 cursor-pointer has-[:checked]:bg-emerald-50 has-[:checked]:border-emerald-300 has-[:checked]:text-emerald-800 text-xs font-bold text-slate-700 transition-all">
                        <input type="radio" name="adjustment_type" value="add" checked class="text-emerald-600 focus:ring-0">
                        <span>+ Stock In</span>
                    </label>
                    <label class="border border-slate-200 rounded-2xl p-3 flex items-center justify-center space-x-2 cursor-pointer has-[:checked]:bg-rose-50 has-[:checked]:border-rose-300 has-[:checked]:text-rose-800 text-xs font-bold text-slate-700 transition-all">
                        <input type="radio" name="adjustment_type" value="subtract" class="text-rose-600 focus:ring-0">
                        <span>- Stock Out</span>
                    </label>
                    <label class="border border-slate-200 rounded-2xl p-3 flex items-center justify-center space-x-2 cursor-pointer has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300 has-[:checked]:text-blue-800 text-xs font-bold text-slate-700 transition-all">
                        <input type="radio" name="adjustment_type" value="set" class="text-blue-600 focus:ring-0">
                        <span>= Set Exact</span>
                    </label>
                </div>
            </div>

            <!-- Quantity Amount -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Quantity Amount <span class="text-[#EB3E0B]">*</span></label>
                <input type="number" name="amount" min="1" value="1" required class="w-full bg-slate-50 text-slate-800 text-base font-extrabold px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
            </div>

            <!-- Reason Selector -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Reason / Movement Category</label>
                <select name="reason" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-medium">
                    <option value="Received Supplier Shipment">Received Supplier Shipment (+ In)</option>
                    <option value="Customer POS Deployment / Sale">Customer POS Deployment / Sale (- Out)</option>
                    <option value="Replacement for Warranty Ticket">Replacement for Warranty Ticket (- Out)</option>
                    <option value="Defective / RMA Return">Defective / Damaged RMA Return</option>
                    <option value="Physical Inventory Count Audit">Physical Inventory Count Audit</option>
                    <option value="Store Transfer">Store / Branch Transfer</option>
                    <option value="Other">Other Adjustment</option>
                </select>
            </div>

            <!-- Client Association (Optional) -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Associated Client Account (Optional)</label>
                <select name="accountnum" onchange="updateClientName(this)" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    <option value="">None / General Stock</option>
                    <?php foreach ($clients_list as $c): ?>
                        <option value="<?php echo sanitize($c['accountnum']); ?>" data-tradename="<?php echo sanitize($c['tradename']); ?>">
                            <?php echo sanitize($c['tradename'] . ' (Acct: ' . $c['accountnum'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="client_name" id="adj_client_name" value="">
            </div>

            <!-- Additional Notes -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Additional Notes / Ticket #</label>
                <input type="text" name="notes" placeholder="e.g., Ticket # RNZ-2026-00045, PO # 8841" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
            </div>

            <!-- Access Level Tier Banner & Input -->
            <?php if ($my_tier === 1): ?>
                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot adjust stock.</span>
                </div>
            <?php elseif ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                    <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Access Code Required (Level 2 Account)</span>
                    </label>
                    <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeAdjustModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <?php if ($my_tier === 1): ?>
                    <button type="button" disabled class="px-6 py-2.5 rounded-2xl bg-slate-300 text-slate-500 font-bold text-xs cursor-not-allowed">
                        🔒 View Only
                    </button>
                <?php else: ?>
                    <button type="submit" class="px-6 py-2.5 rounded-2xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-sm shadow-blue-600/30 transition-all">
                        Apply Stock Adjustment
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: EDIT ITEM -->
<!-- ========================================================================= -->
<div id="editModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-xl w-full p-6 sm:p-8 space-y-6 max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-slate-800 text-white flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Edit Hardware Item</h3>
                    <p class="text-xs text-slate-500">Update specifications, pricing, thresholds or status.</p>
                </div>
            </div>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4" id="editItemForm" onsubmit="return validateEditItemForm();">
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="item_id" id="edit_item_id" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Item Name -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Item Name <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="name" id="edit_name" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Item SKU / Code -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">SKU / Item Code</label>
                    <input type="text" name="item_code" id="edit_item_code" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono uppercase">
                </div>

                <!-- Materials Cost Price -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700 flex items-center justify-between">
                        <span>Materials Cost Price (₱)</span>
                        <span class="text-[10px] text-slate-400 font-normal">Capital / Purchase</span>
                    </label>
                    <input type="number" name="cost_price" id="edit_cost_price" step="0.01" min="0" oninput="calculateEditMargin()" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Selling Price -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700 flex items-center justify-between">
                        <span>Selling / SRP Price (₱)</span>
                        <span class="text-[10px] text-emerald-600 font-semibold">Client / Retail</span>
                    </label>
                    <input type="number" name="selling_price" id="edit_selling_price" step="0.01" min="0" oninput="calculateEditMargin()" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Profit / Margin Indicator -->
                <div class="sm:col-span-2 p-2.5 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between text-xs">
                    <span class="text-slate-500 font-medium">Estimated Markup & Unit Margin:</span>
                    <span id="edit_margin_preview" class="font-mono font-bold text-slate-700">₱0.00 (0.0%)</span>
                </div>

                <!-- Minimum Threshold -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Min Alert Threshold</label>
                    <input type="number" name="min_threshold" id="edit_min_threshold" min="0" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Status -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Item Status</label>
                    <select name="status" id="edit_status" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-semibold">
                        <option value="Active">Active (visible to clients)</option>
                        <option value="Inactive">Inactive (hidden from clients)</option>
                        <option value="Discontinued">Discontinued (hidden from clients)</option>
                    </select>
                    <p class="text-[10px] text-slate-500">Only Active items appear in the client ordering catalog.</p>
                </div>

                <!-- Stock split by condition -->
                <div class="sm:col-span-2 space-y-2 pt-3 border-t border-slate-100">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-bold text-slate-700">Stock by Condition</label>
                        <span class="text-[11px] text-slate-500">Total: <strong id="edit_qty_total" class="font-mono text-slate-900">0</strong></span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider">Good</label>
                            <input type="number" name="qty_good" id="edit_qty_good" min="0" step="1" value="0" oninput="updateEditQtyTotal()" class="w-full bg-emerald-50/60 text-slate-800 text-xs px-3 py-2.5 rounded-2xl border border-emerald-200 focus:border-emerald-500 focus:bg-white focus:outline-none font-mono font-bold">
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-amber-700 uppercase tracking-wider">Defective</label>
                            <input type="number" name="qty_defective" id="edit_qty_defective" min="0" step="1" value="0" oninput="updateEditQtyTotal()" class="w-full bg-amber-50/60 text-slate-800 text-xs px-3 py-2.5 rounded-2xl border border-amber-200 focus:border-amber-500 focus:bg-white focus:outline-none font-mono font-bold">
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-rose-700 uppercase tracking-wider">Scrap</label>
                            <input type="number" name="qty_damaged" id="edit_qty_damaged" min="0" step="1" value="0" oninput="updateEditQtyTotal()" class="w-full bg-rose-50/60 text-slate-800 text-xs px-3 py-2.5 rounded-2xl border border-rose-200 focus:border-rose-500 focus:bg-white focus:outline-none font-mono font-bold">
                        </div>
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">Diagnostic</label>
                            <input type="number" name="qty_diagnostic" id="edit_qty_diagnostic" min="0" step="1" value="0" oninput="updateEditQtyTotal()" class="w-full bg-slate-100 text-slate-800 text-xs px-3 py-2.5 rounded-2xl border border-slate-200 focus:border-slate-500 focus:bg-white focus:outline-none font-mono font-bold">
                        </div>
                    </div>
                    <p class="text-[10px] text-slate-500">Total stock is the sum of these four. Any change here is written to the movement log.</p>
                </div>

                <!-- Description -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Description / Specs</label>
                    <textarea name="description" id="edit_description" rows="2" class="w-full bg-slate-50 text-slate-800 text-xs p-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none"></textarea>
                </div>
            </div>

            <!-- Access Level Tier Banner & Input -->
            <?php if ($my_tier === 1): ?>
                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot edit item details.</span>
                </div>
            <?php elseif ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                    <label for="edit_access_code" class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Access Code Required (Level 2 Account)</span>
                    </label>
                    <input type="password" name="action_access_code" id="edit_access_code" required minlength="4" maxlength="32" autocomplete="off" spellcheck="false" oninput="clearEditCodeError()" onblur="validateEditAccessCode(false)" placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                    <p id="edit_access_code_error" class="hidden text-[11px] font-bold text-rose-700"></p>
                    <p id="edit_access_code_hint" class="text-[10px] text-amber-800/80">Your code confirms this change and is verified again on the server. 3 incorrect codes lock editing for 60 seconds.</p>
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeEditModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <?php if ($my_tier === 1): ?>
                    <button type="button" disabled class="px-6 py-2.5 rounded-2xl bg-slate-300 text-slate-500 font-bold text-xs cursor-not-allowed">
                        🔒 View Only
                    </button>
                <?php else: ?>
                    <button type="submit" class="px-6 py-2.5 rounded-2xl bg-slate-900 text-white font-bold text-xs hover:bg-slate-800 transition-all">
                        Update Details
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: DISABLE / ENABLE HARDWARE ITEM (CLIENT CATALOG VISIBILITY) -->
<!-- ========================================================================= -->
<div id="statusModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-lg w-full p-6 sm:p-8 space-y-6 max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div id="status_modal_icon" class="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                </div>
                <div>
                    <h3 id="status_modal_title" class="font-extrabold text-lg text-slate-900">Disable Hardware Item</h3>
                    <p class="text-xs text-slate-500">Control whether clients can see and order this item.</p>
                </div>
            </div>
            <button type="button" onclick="closeStatusModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4" id="statusItemForm" onsubmit="return validateStatusForm();">
            <input type="hidden" name="action" value="set_item_status">
            <input type="hidden" name="item_id" id="status_item_id" value="0">
            <input type="hidden" name="new_status" id="status_new_status" value="Inactive">

            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-1">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Hardware Item</p>
                <p id="status_item_name" class="font-extrabold text-sm text-slate-900">-</p>
                <p class="text-[11px] font-mono text-[#EB3E0B]" id="status_item_code">-</p>
                <p class="text-[11px] text-slate-500">Current availability: <strong id="status_current" class="text-slate-800">-</strong></p>
            </div>

            <div id="status_explainer" class="p-3.5 rounded-2xl text-xs leading-relaxed border bg-amber-50 border-amber-200 text-amber-900">
                Disabling hides this item from the client ordering catalog, so no new orders can be placed for it.
                Stock levels, pricing and movement history stay exactly as they are, and you can enable it again anytime.
            </div>

            <!-- Optional reason, stored in the movement audit log -->
            <div class="space-y-1">
                <label for="status_reason" class="text-xs font-bold text-slate-700">Reason <span class="text-slate-400 font-medium">(optional, saved to the audit log)</span></label>
                <textarea name="status_reason" id="status_reason" rows="2" maxlength="255" placeholder="e.g. Supplier discontinued this model / out of stock indefinitely" class="w-full bg-slate-50 text-slate-800 text-xs p-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none"></textarea>
            </div>

            <!-- Access Level Tier Banner & Input -->
            <?php if ($my_tier === 1): ?>
                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot change item availability.</span>
                </div>
            <?php elseif ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                    <label for="status_access_code" class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Access Code Required (Level 2 Account)</span>
                    </label>
                    <input type="password" name="action_access_code" id="status_access_code" required minlength="4" maxlength="32" autocomplete="off" spellcheck="false" oninput="clearStatusCodeError()" onblur="validateStatusAccessCode(false)" placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                    <p id="status_access_code_error" class="hidden text-[11px] font-bold text-rose-700"></p>
                    <p class="text-[10px] text-amber-800/80">Your code confirms this change and is verified again on the server. 3 incorrect codes lock inventory changes for 60 seconds.</p>
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeStatusModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <?php if ($my_tier === 1): ?>
                    <button type="button" disabled class="px-6 py-2.5 rounded-2xl bg-slate-300 text-slate-500 font-bold text-xs cursor-not-allowed">
                        &#128274; View Only
                    </button>
                <?php else: ?>
                    <button type="submit" id="status_submit_btn" class="px-6 py-2.5 rounded-2xl bg-amber-500 text-white font-bold text-xs hover:bg-amber-600 transition-all">
                        Disable Item
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: STOCK MOVEMENT AUDIT LOG -->
<!-- ========================================================================= -->
<div id="movementLogModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-3xl w-full p-6 sm:p-8 space-y-6 max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Stock Movement Audit History</h3>
                    <p class="text-xs text-slate-500">Live ledger of all stock in, stock out, client allocations and adjustments.</p>
                </div>
            </div>
            <button type="button" onclick="closeMovementLogModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="overflow-x-auto max-h-96 pr-1">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[10px] sticky top-0">
                        <th class="py-2.5 px-4">Date / Time</th>
                        <th class="py-2.5 px-4">Hardware Item</th>
                        <th class="py-2.5 px-4 text-center">Change Type</th>
                        <th class="py-2.5 px-4 text-center">Qty Change</th>
                        <th class="py-2.5 px-4">Recorded By</th>
                        <th class="py-2.5 px-4">Client / Reason Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($recent_logs)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-slate-400">
                                No stock movements recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_logs as $log): 
                            $chg = intval($log['quantity_change']);
                            if ($chg > 0) {
                                $chg_badge = 'bg-emerald-50 text-emerald-700 font-bold';
                                $chg_str = '+' . $chg;
                            } elseif ($chg < 0) {
                                $chg_badge = 'bg-rose-50 text-rose-700 font-bold';
                                $chg_str = strval($chg);
                            } else {
                                $chg_badge = 'bg-slate-100 text-slate-700 font-bold';
                                $chg_str = '0';
                            }
                        ?>
                            <tr class="hover:bg-slate-50/80">
                                <td class="py-3 px-4 text-slate-500 whitespace-nowrap">
                                    <?php echo format_date($log['created_at']); ?>
                                </td>
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    <?php echo sanitize($log['item_name']); ?>
                                    <span class="block text-[10px] font-mono text-slate-400"><?php echo sanitize($log['item_code']); ?></span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php 
                                        $type_str = sanitize($log['change_type']);
                                        if (strpos(strtolower($log['change_type']), 'pull out') !== false || strpos(strtolower($log['change_type']), 'pull-out') !== false) {
                                            $t_badge = 'bg-amber-100 text-amber-900 border border-amber-300 font-bold';
                                        } elseif ($chg > 0) {
                                            $t_badge = 'bg-emerald-100 text-emerald-800 border border-emerald-300 font-bold';
                                        } elseif ($chg < 0) {
                                            $t_badge = 'bg-rose-100 text-rose-800 border border-rose-300 font-bold';
                                        } else {
                                            $t_badge = 'bg-slate-100 text-slate-700 border border-slate-200 font-bold';
                                        }
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] <?php echo $t_badge; ?>">
                                        <?php echo $type_str; ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-mono <?php echo $chg_badge; ?>">
                                        <?php echo $chg_str; ?>
                                    </span>
                                    <span class="block text-[9px] text-slate-400 font-mono">(<?php echo $log['previous_quantity']; ?> &rarr; <?php echo $log['new_quantity']; ?>)</span>
                                </td>
                                <td class="py-3 px-4 font-semibold text-slate-800">
                                    <?php echo sanitize($log['tech_name']); ?>
                                </td>
                                <td class="py-3 px-4 text-slate-600 max-w-xs truncate">
                                    <?php if (!empty($log['client_name'])): ?>
                                        <strong class="text-slate-900 block"><?php echo sanitize($log['client_name']); ?></strong>
                                    <?php endif; ?>
                                    <span class="text-xs"><?php echo sanitize($log['notes']); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pt-4 border-t border-slate-100 flex items-center justify-end">
            <button type="button" onclick="closeMovementLogModal()" class="px-5 py-2.5 rounded-2xl bg-slate-900 text-white font-bold text-xs hover:bg-slate-800 transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<script>
// Pull Out Modal Handlers

// Full active inventory (used when deploying TO a client) and the hardware each
// client already has on record in accounts.php (used when pulling FROM a client).
var PULLOUT_INVENTORY = <?php
    $js_inv = array();
    foreach ($all_inventory_items as $inv_item) {
        $ic = isset($inv_item['cost_price']) ? floatval($inv_item['cost_price']) : 0.00;
        $ip = (isset($inv_item['selling_price']) && floatval($inv_item['selling_price']) > 0) ? floatval($inv_item['selling_price']) : floatval($inv_item['unit_price']);
        $js_inv[] = array(
            'id' => intval($inv_item['id']),
            'name' => $inv_item['name'],
            'code' => $inv_item['item_code'],
            'qty' => intval($inv_item['qty_good']),
            'cost' => $ic,
            'price' => $ip
        );
    }
    echo json_encode($js_inv);
?>;
var PULLOUT_CLIENT_HARDWARE = <?php echo json_encode($client_hardware_map); ?>;

function pesoFmt(n) {
    return '₱' + parseFloat(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function pulloutDirectionValue() {
    var checked = document.querySelector('input[name="pullout_direction"]:checked');
    return checked ? checked.value : 'from_client';
}

// Rebuilds the Hardware Item dropdown to match the chosen direction and client.
function refreshPulloutItemOptions(keepValue) {
    var sel = document.getElementById('pullout_item_id');
    if (!sel) return;

    var direction = pulloutDirectionValue();
    var acct = document.getElementById('pullout_accountnum').value;
    var previous = (typeof keepValue !== 'undefined') ? keepValue : sel.value;
    var label = document.getElementById('pullout_item_label');
    var hint = document.getElementById('pullout_item_hint');

    sel.innerHTML = '';

    if (direction === 'to_client') {
        if (label) label.textContent = 'Hardware Item (from warehouse inventory)';
        if (hint) hint.textContent = 'Deploying will deduct warehouse stock and add this item to the client’s Software & Hardware records.';

        sel.appendChild(new Option('-- Choose Hardware Item --', ''));
        for (var i = 0; i < PULLOUT_INVENTORY.length; i++) {
            var it = PULLOUT_INVENTORY[i];
            var o = new Option(it.name + ' (' + it.code + ') — ' + it.qty + ' good in stock [Cost: ' + pesoFmt(it.cost) + ' | Sell: ' + pesoFmt(it.price) + ']', it.id);
            o.setAttribute('data-name', it.name);
            o.setAttribute('data-code', it.code);
            o.setAttribute('data-qty', it.qty);
            o.setAttribute('data-cost', it.cost);
            o.setAttribute('data-price', it.price);
            sel.appendChild(o);
        }
    } else {
        if (label) label.textContent = 'Hardware Item (recorded for this client)';

        if (!acct) {
            if (hint) hint.textContent = 'Choose a client account first — the list will show only the hardware recorded for them.';
            sel.appendChild(new Option('-- Choose a client account first --', ''));
            updatePullOutItem(sel);
            return;
        }

        var owned = PULLOUT_CLIENT_HARDWARE[acct] || [];
        if (owned.length === 0) {
            if (hint) hint.textContent = 'This client has no hardware recorded in their account yet.';
            sel.appendChild(new Option('-- No hardware recorded for this client --', ''));
            updatePullOutItem(sel);
            return;
        }

        if (hint) hint.textContent = 'Showing only hardware recorded on this client’s account.';
        sel.appendChild(new Option('-- Choose Hardware Item --', ''));

        for (var j = 0; j < owned.length; j++) {
            var a = owned[j];
            // Pull current stock/pricing from inventory when the item still exists there
            var inv = null;
            for (var k = 0; k < PULLOUT_INVENTORY.length; k++) {
                if (PULLOUT_INVENTORY[k].id === a.item_id) { inv = PULLOUT_INVENTORY[k]; break; }
            }
            var text = a.name + ' (' + (a.item_code || '') + ')';
            if (a.serial_number) text += ' — S/N ' + a.serial_number;
            text += ' — ' + a.quantity + ' with client';

            var opt = new Option(text, a.item_id);
            opt.setAttribute('data-name', a.name);
            opt.setAttribute('data-code', a.item_code || '');
            opt.setAttribute('data-qty', inv ? inv.qty : 0);
            opt.setAttribute('data-cost', inv ? inv.cost : 0);
            opt.setAttribute('data-price', inv ? inv.price : a.unit_price);
            opt.setAttribute('data-serial', a.serial_number || '');
            opt.setAttribute('data-owned', a.quantity);
            sel.appendChild(opt);
        }
    }

    // Restore the previous choice when it is still available in the rebuilt list
    if (previous) {
        sel.value = previous;
        if (sel.value !== previous) sel.value = '';
    }
    updatePullOutItem(sel);
}

function openPullOutModal(presetItemId, presetAcct) {
    if (presetAcct) {
        var selClient = document.getElementById('pullout_accountnum');
        if (selClient) {
            selClient.value = presetAcct;
            updatePullOutClient(selClient);
        }
    }
    refreshPulloutItemOptions(presetItemId || '');
    if (presetItemId) {
        var selItem = document.getElementById('pullout_item_id');
        if (selItem) {
            selItem.value = presetItemId;
            updatePullOutItem(selItem);
        }
    }
    updateRestockTarget();
    var m = document.getElementById('pullOutModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function openPullOutModalForItem(item) {
    if (!item) return;
    // Items opened from an inventory row are warehouse stock, so default to deploying
    var toClient = document.querySelector('input[name="pullout_direction"][value="to_client"]');
    if (toClient) {
        toClient.checked = true;
        togglePulloutDirection('to_client');
    }
    openPullOutModal(item.id, null);
}

function closePullOutModal() {
    var m = document.getElementById('pullOutModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

// Mirrors condition_to_stock_column() in inventory_init.php so the modal can
// show which stock bucket a pulled-out unit is about to land in.
function conditionToBucketLabel(condition) {
    var c = (condition || '').toLowerCase();
    if (c.indexOf('damaged') !== -1 || c.indexOf('scrap') !== -1 || c.indexOf('beyond repair') !== -1) return 'Scrap';
    if (c.indexOf('diagnostic') !== -1 || c.indexOf('bench') !== -1) return 'Diagnostic';
    if (c.indexOf('defective') !== -1 || c.indexOf('needs repair') !== -1 || c.indexOf('for repair') !== -1) return 'Defective';
    return 'Good';
}

function updateRestockTarget() {
    var sel = document.getElementById('pullout_condition_status');
    var label = document.getElementById('restock_target_label');
    if (label && sel) {
        label.textContent = conditionToBucketLabel(sel.value);
    }
    var itemSel = document.getElementById('pullout_item_id');
    var itemLabel = document.getElementById('restock_item_label');
    if (itemLabel && itemSel) {
        var opt = itemSel.options[itemSel.selectedIndex];
        var name = (opt && opt.value) ? (opt.getAttribute('data-name') || '') : '';
        itemLabel.textContent = name !== '' ? name : 'this item';
    }
}

function togglePulloutDirection(val) {
    var restockBox = document.getElementById('restock_option_box');
    if (restockBox) {
        if (val === 'to_client') {
            restockBox.classList.add('hidden');
        } else {
            restockBox.classList.remove('hidden');
        }
    }
    refreshPulloutItemOptions('');
    updateRestockTarget();
}

function updatePullOutItem(sel) {
    var opt = sel.options[sel.selectedIndex];
    var infoBox = document.getElementById('pullout_item_info');
    if (opt && opt.value) {
        var name = opt.getAttribute('data-name') || '';
        var qty = opt.getAttribute('data-qty') || '0';
        var cost = parseFloat(opt.getAttribute('data-cost') || 0);
        var price = parseFloat(opt.getAttribute('data-price') || 0);

        document.getElementById('pullout_item_name_text').textContent = name;
        document.getElementById('pullout_item_qty_text').textContent = qty;

        var pricingEl = document.getElementById('pullout_item_pricing_text');
        if (pricingEl) {
            pricingEl.textContent = 'Cost: ' + pesoFmt(cost) + ' | Sell: ' + pesoFmt(price);
        }

        // When pulling from a client, prefill the serial recorded on their account
        var serial = opt.getAttribute('data-serial');
        var serialField = document.querySelector('#pullOutModal input[name="serial_number"]');
        if (serial && serialField && !serialField.value) {
            serialField.value = serial;
        }
        if (infoBox) infoBox.classList.remove('hidden');
    } else {
        if (infoBox) infoBox.classList.add('hidden');
    }
    updateRestockTarget();
}

function updatePullOutClient(sel) {
    var opt = sel.options[sel.selectedIndex];
    var tradename = opt ? (opt.getAttribute('data-tradename') || '') : '';
    var address = opt ? (opt.getAttribute('data-address') || '') : '';
    document.getElementById('pullout_client_name').value = tradename;
    document.getElementById('pullout_client_address').value = address;
    // The client's own hardware drives the item list when pulling FROM them
    if (pulloutDirectionValue() === 'from_client') {
        refreshPulloutItemOptions('');
    }
}

// Real-time Profit & Margin Calculators
function calculateAddMargin() {
    var cost = parseFloat(document.getElementById('add_cost_price').value) || 0;
    var sell = parseFloat(document.getElementById('add_selling_price').value) || 0;
    var profit = sell - cost;
    var pct = cost > 0 ? ((profit / cost) * 100).toFixed(1) : (sell > 0 ? '100.0' : '0.0');
    var label = (profit >= 0 ? '+' : '') + '₱' + profit.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + pct + '%)';
    
    var el = document.getElementById('add_margin_preview');
    if (el) {
        el.textContent = label;
        el.className = profit >= 0 ? 'font-mono font-bold text-emerald-700' : 'font-mono font-bold text-rose-600';
    }
}

function calculateEditMargin() {
    var cost = parseFloat(document.getElementById('edit_cost_price').value) || 0;
    var sell = parseFloat(document.getElementById('edit_selling_price').value) || 0;
    var profit = sell - cost;
    var pct = cost > 0 ? ((profit / cost) * 100).toFixed(1) : (sell > 0 ? '100.0' : '0.0');
    var label = (profit >= 0 ? '+' : '') + '₱' + profit.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + pct + '%)';
    
    var el = document.getElementById('edit_margin_preview');
    if (el) {
        el.textContent = label;
        el.className = profit >= 0 ? 'font-mono font-bold text-emerald-700' : 'font-mono font-bold text-rose-600';
    }
}

// Modal Handlers
function openAddItemModal() {
    calculateAddMargin();
    var m = document.getElementById('addItemModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeAddItemModal() {
    var m = document.getElementById('addItemModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function openAdjustModal(item) {
    if (!item) return;
    document.getElementById('adj_item_id').value = item.id;
    document.getElementById('adj_item_name_display').textContent = item.name;
    document.getElementById('adj_current_qty').textContent = item.quantity;
    document.getElementById('adj_item_code').textContent = item.item_code;
    document.getElementById('adj_qty_good').textContent = item.qty_good || 0;
    document.getElementById('adj_qty_defective').textContent = item.qty_defective || 0;
    document.getElementById('adj_qty_damaged').textContent = item.qty_damaged || 0;
    document.getElementById('adj_qty_diagnostic').textContent = item.qty_diagnostic || 0;
    document.getElementById('adj_stock_condition').value = 'qty_good';
    
    var m = document.getElementById('adjustModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeAdjustModal() {
    var m = document.getElementById('adjustModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function updateClientName(sel) {
    var opt = sel.options[sel.selectedIndex];
    var tradename = opt ? (opt.getAttribute('data-tradename') || '') : '';
    document.getElementById('adj_client_name').value = tradename;
}

// Keeps the edit modal's total in step with the four condition inputs
function updateEditQtyTotal() {
    var ids = ['edit_qty_good', 'edit_qty_defective', 'edit_qty_damaged', 'edit_qty_diagnostic'];
    var total = 0;
    for (var i = 0; i < ids.length; i++) {
        var v = parseInt(document.getElementById(ids[i]).value, 10);
        if (isNaN(v) || v < 0) v = 0;
        total += v;
    }
    var out = document.getElementById('edit_qty_total');
    if (out) out.textContent = total;
}

// ---------------------------------------------------------------
// Security Access Code validation, shared by the Edit and the
// Disable / Enable modals ('edit' and 'status' field prefixes).
// This only catches obvious mistakes before the round trip - the server
// re-verifies the code in verify_inventory_action_code() either way.
// ---------------------------------------------------------------
function showCodeError(prefix, message) {
    var box = document.getElementById(prefix + '_access_code_error');
    var input = document.getElementById(prefix + '_access_code');
    if (box) {
        box.textContent = message || '';
        if (message) {
            box.classList.remove('hidden');
        } else {
            box.classList.add('hidden');
        }
    }
    if (input) {
        if (message) {
            input.classList.add('border-rose-400', 'bg-rose-50');
        } else {
            input.classList.remove('border-rose-400', 'bg-rose-50');
        }
    }
}

// Returns true when the typed code looks usable. Pass strict = true when the
// form is actually being submitted, so an empty field is also rejected.
function validateCodeField(prefix, strict, emptyMessage) {
    var input = document.getElementById(prefix + '_access_code');
    if (!input) return true; // Level 3 account: no code field is rendered

    var code = input.value || '';
    if (code === '') {
        if (strict) {
            showCodeError(prefix, emptyMessage);
            return false;
        }
        return true;
    }
    if (/\s/.test(code)) {
        showCodeError(prefix, 'The security access code cannot contain spaces.');
        return false;
    }
    if (code.length < 4) {
        showCodeError(prefix, 'Security access code is too short - it must be at least 4 characters.');
        return false;
    }
    if (code.length > 32) {
        showCodeError(prefix, 'Security access code is too long - 32 characters maximum.');
        return false;
    }
    showCodeError(prefix, '');
    return true;
}

function focusCodeField(prefix) {
    var input = document.getElementById(prefix + '_access_code');
    if (input) input.focus();
}

// --- Edit Hardware Item ---
function showEditCodeError(message) {
    showCodeError('edit', message);
}
function clearEditCodeError() {
    showCodeError('edit', '');
}
function validateEditAccessCode(strict) {
    return validateCodeField('edit', strict, 'Enter your security access code to save these changes.');
}
function validateEditItemForm() {
    if (!validateEditAccessCode(true)) {
        focusCodeField('edit');
        return false;
    }
    return true;
}

// --- Disable / Enable Hardware Item ---
function clearStatusCodeError() {
    showCodeError('status', '');
}
function validateStatusAccessCode(strict) {
    return validateCodeField('status', strict, 'Enter your security access code to confirm this change.');
}
function validateStatusForm() {
    if (!validateStatusAccessCode(true)) {
        focusCodeField('status');
        return false;
    }
    return true;
}

// Opens the availability modal, pre-set to the opposite of the item's current
// state: an Active item is offered for disabling, anything else for enabling.
function openStatusModal(item) {
    if (!item) return;
    var current = item.status || 'Active';
    var disabling = (current === 'Active');
    var nextStatus = disabling ? 'Inactive' : 'Active';

    document.getElementById('status_item_id').value = item.id;
    document.getElementById('status_new_status').value = nextStatus;
    document.getElementById('status_item_name').textContent = item.name || '-';
    document.getElementById('status_item_code').textContent = item.item_code || '-';
    document.getElementById('status_current').textContent = current + (disabling ? ' (visible to clients)' : ' (hidden from clients)');

    var title = document.getElementById('status_modal_title');
    var icon = document.getElementById('status_modal_icon');
    var explainer = document.getElementById('status_explainer');
    var btn = document.getElementById('status_submit_btn');

    if (disabling) {
        title.textContent = 'Disable Hardware Item';
        icon.className = 'w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center font-bold';
        explainer.className = 'p-3.5 rounded-2xl text-xs leading-relaxed border bg-amber-50 border-amber-200 text-amber-900';
        explainer.textContent = 'Disabling hides this item from the client ordering catalog, so no new orders can be placed for it. Stock levels, pricing and movement history stay exactly as they are, and you can enable it again anytime.';
        if (btn) {
            btn.textContent = 'Disable Item';
            btn.className = 'px-6 py-2.5 rounded-2xl bg-amber-500 text-white font-bold text-xs hover:bg-amber-600 transition-all';
        }
    } else {
        title.textContent = 'Enable Hardware Item';
        icon.className = 'w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold';
        explainer.className = 'p-3.5 rounded-2xl text-xs leading-relaxed border bg-emerald-50 border-emerald-200 text-emerald-900';
        explainer.textContent = 'Enabling puts this item back in the client ordering catalog, so clients can see and order it again.';
        if (btn) {
            btn.textContent = 'Enable Item';
            btn.className = 'px-6 py-2.5 rounded-2xl bg-emerald-600 text-white font-bold text-xs hover:bg-emerald-700 transition-all';
        }
    }

    // Never carry a previous reason or access code into a new confirmation
    var reason = document.getElementById('status_reason');
    if (reason) reason.value = '';
    var codeInput = document.getElementById('status_access_code');
    if (codeInput) codeInput.value = '';
    showCodeError('status', '');

    var m = document.getElementById('statusModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeStatusModal() {
    var m = document.getElementById('statusModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function openEditModal(item) {
    if (!item) return;
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_name').value = item.name || '';
    document.getElementById('edit_item_code').value = item.item_code || '';
    document.getElementById('edit_cost_price').value = item.cost_price ? parseFloat(item.cost_price).toFixed(2) : '0.00';
    var sellPrice = (item.selling_price && parseFloat(item.selling_price) > 0) ? item.selling_price : (item.unit_price || 0.00);
    document.getElementById('edit_selling_price').value = parseFloat(sellPrice).toFixed(2);
    document.getElementById('edit_min_threshold').value = item.min_threshold || 5;
    document.getElementById('edit_status').value = item.status || 'Active';
    document.getElementById('edit_description').value = item.description || '';
    document.getElementById('edit_qty_good').value = parseInt(item.qty_good, 10) || 0;
    document.getElementById('edit_qty_defective').value = parseInt(item.qty_defective, 10) || 0;
    document.getElementById('edit_qty_damaged').value = parseInt(item.qty_damaged, 10) || 0;
    document.getElementById('edit_qty_diagnostic').value = parseInt(item.qty_diagnostic, 10) || 0;

    calculateEditMargin();
    updateEditQtyTotal();

    // Never carry a previously typed access code (or its error) into a new edit
    var codeInput = document.getElementById('edit_access_code');
    if (codeInput) codeInput.value = '';
    showEditCodeError('');

    var m = document.getElementById('editModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeEditModal() {
    var m = document.getElementById('editModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function openMovementLogModal() {
    var m = document.getElementById('movementLogModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeMovementLogModal() {
    var m = document.getElementById('movementLogModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

// Auto open modal if URL params present
window.addEventListener('DOMContentLoaded', function() {
    var presetClient = '<?php echo addslashes($preset_pullout_client); ?>';
    var presetItem = '<?php echo intval($preset_pullout_item); ?>';
    if (presetClient !== '' || parseInt(presetItem) > 0) {
        openPullOutModal(parseInt(presetItem) > 0 ? presetItem : null, presetClient !== '' ? presetClient : null);
    }
});

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePullOutModal();
        closeAddItemModal();
        closeAdjustModal();
        closeEditModal();
        closeStatusModal();
        closeMovementLogModal();
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
