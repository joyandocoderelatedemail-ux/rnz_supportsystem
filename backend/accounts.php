<?php
// Manage Accounts Hub for Support Center (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory_init.php';

require_page_access('accounts');
init_inventory_tables();

// Client spend figures are commercially sensitive: Super Admin (Master) only
$can_view_spend = is_super_admin();

$pdo = get_db_connection();

// Handle Account Profile Update Form Submission
$update_msg = '';
$update_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
    $perm_check = check_tech_action_permission($action_code);
    
    if (!$perm_check['allowed']) {
        $update_error = $perm_check['message'];
    } else {
        $action = $_POST['action'];

        if ($action === 'update_client_profile') {
            $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
            $tradename = isset($_POST['tradename']) ? trim($_POST['tradename']) : '';
            $clientname = isset($_POST['clientname']) ? trim($_POST['clientname']) : '';
            $contactnum = isset($_POST['contactnum']) ? trim($_POST['contactnum']) : '';
            $emailaddress = isset($_POST['emailaddress']) ? trim($_POST['emailaddress']) : '';
            $address = isset($_POST['address']) ? trim($_POST['address']) : '';
            $type = isset($_POST['type']) ? trim($_POST['type']) : '';
            $monthlyretainersfee = isset($_POST['monthlyretainersfee']) ? trim($_POST['monthlyretainersfee']) : '';

            if (!empty($accountnum)) {
                try {
                    $stmt_up = $pdo->prepare("UPDATE bucket_client 
                        SET tradename = :tname, 
                            clientname = :cname, 
                            contactnum = :contact, 
                            emailaddress = :email, 
                            address = :addr, 
                            type = :type, 
                            monthlyretainersfee = :fee 
                        WHERE accountnum = :acct");

                    $stmt_up->execute(array(
                        ':tname' => $tradename,
                        ':cname' => $clientname,
                        ':contact' => $contactnum,
                        ':email' => $emailaddress,
                        ':addr' => $address,
                        ':type' => $type,
                        ':fee' => $monthlyretainersfee,
                        ':acct' => $accountnum
                    ));

                    $update_msg = "Client Account #$accountnum profile updated successfully!";
                } catch (PDOException $e) {
                    $update_error = "Error updating account: " . $e->getMessage();
                }
            }
        } elseif ($action === 'update_client_warranty') {
            $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
            $warranty_status = isset($_POST['warranty_status']) ? trim($_POST['warranty_status']) : 'Inactive';
            $warranty_coverage_type = isset($_POST['warranty_coverage_type']) ? trim($_POST['warranty_coverage_type']) : 'Both';
            $warranty_expiry = isset($_POST['warranty_expiry']) && !empty($_POST['warranty_expiry']) ? trim($_POST['warranty_expiry']) : null;
            $warranty_notes = isset($_POST['warranty_notes']) ? trim($_POST['warranty_notes']) : '';

            if (!empty($accountnum)) {
                try {
                    $stmt_w = $pdo->prepare("UPDATE bucket_client 
                        SET warranty_status = :status, 
                            warranty_coverage_type = :cov_type, 
                            warranty_expiry = :expiry, 
                            warranty_notes = :notes 
                        WHERE accountnum = :acct");

                    $stmt_w->execute(array(
                        ':status' => $warranty_status,
                        ':cov_type' => $warranty_coverage_type,
                        ':expiry' => $warranty_expiry,
                        ':notes' => $warranty_notes,
                        ':acct' => $accountnum
                    ));

                    $update_msg = ($warranty_status === 'Active') 
                        ? "Active Warranty ($warranty_coverage_type) granted to Account #$accountnum successfully!" 
                        : "Warranty status set to Inactive for Account #$accountnum.";
                } catch (PDOException $e) {
                    $update_error = "Error updating warranty: " . $e->getMessage();
                }
            }
        } elseif ($action === 'remove_client_warranty') {
            $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
            if (!empty($accountnum)) {
                try {
                    $stmt_w = $pdo->prepare("UPDATE bucket_client 
                        SET warranty_status = 'Inactive', 
                            warranty_expiry = NULL, 
                            warranty_notes = NULL 
                        WHERE accountnum = :acct");
                    $stmt_w->execute(array(':acct' => $accountnum));
                    $update_msg = "Active warranty removed for Account #$accountnum.";
                } catch (PDOException $e) {
                    $update_error = "Error removing warranty: " . $e->getMessage();
                }
            }
        } elseif ($action === 'create_client') {
            $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
            $tradename = isset($_POST['tradename']) ? trim($_POST['tradename']) : '';
            $clientname = isset($_POST['clientname']) ? trim($_POST['clientname']) : '';
            $contactnum = isset($_POST['contactnum']) ? trim($_POST['contactnum']) : '';
            $emailaddress = isset($_POST['emailaddress']) ? trim($_POST['emailaddress']) : '';
            $address = isset($_POST['address']) ? trim($_POST['address']) : '';
            $type = isset($_POST['type']) && $_POST['type'] !== '' ? trim($_POST['type']) : 'Client';
            $monthlyretainersfee = isset($_POST['monthlyretainersfee']) && $_POST['monthlyretainersfee'] !== '' ? $_POST['monthlyretainersfee'] : 0;
            $outstandingbalance = isset($_POST['outstandingbalance']) && $_POST['outstandingbalance'] !== '' ? $_POST['outstandingbalance'] : 0;
            $warranty_status = isset($_POST['warranty_status']) ? trim($_POST['warranty_status']) : 'Inactive';
            $warranty_coverage_type = isset($_POST['warranty_coverage_type']) ? trim($_POST['warranty_coverage_type']) : 'Both';
            $warranty_expiry = isset($_POST['warranty_expiry']) && !empty($_POST['warranty_expiry']) ? trim($_POST['warranty_expiry']) : null;
            $warranty_notes = isset($_POST['warranty_notes']) ? trim($_POST['warranty_notes']) : '';

            if (empty($accountnum) || empty($tradename) || empty($clientname)) {
                $update_error = "Account Number, Trade Business Name and Owner / Client Name are all required.";
            } else {
                try {
                    // accountnum doubles as the client's portal password, so it must be unique
                    $stmt_dupe = $pdo->prepare("SELECT id FROM bucket_client WHERE accountnum = :acct LIMIT 1");
                    $stmt_dupe->execute(array(':acct' => $accountnum));

                    if ($stmt_dupe->fetch()) {
                        $update_error = "Account #$accountnum already exists. Please use a different account number.";
                    } else {
                        $stmt_new = $pdo->prepare("INSERT INTO bucket_client 
                            (accountnum, type, clientname, tradename, address, contactnum, emailaddress, 
                             monthlyretainersfee, outstandingbalance, warranty_status, warranty_expiry, 
                             warranty_notes, warranty_coverage_type) 
                            VALUES 
                            (:acct, :type, :cname, :tname, :addr, :contact, :email, 
                             :fee, :balance, :w_status, :w_expiry, 
                             :w_notes, :w_cov)");

                        $stmt_new->execute(array(
                            ':acct' => $accountnum,
                            ':type' => $type,
                            ':cname' => $clientname,
                            ':tname' => $tradename,
                            ':addr' => $address,
                            ':contact' => $contactnum,
                            ':email' => $emailaddress,
                            ':fee' => $monthlyretainersfee,
                            ':balance' => $outstandingbalance,
                            ':w_status' => $warranty_status,
                            ':w_expiry' => $warranty_expiry,
                            ':w_notes' => $warranty_notes,
                            ':w_cov' => $warranty_coverage_type
                        ));

                        $update_msg = "New client \"$tradename\" registered successfully as Account #$accountnum. The client can now sign in to the portal using their Trade Name and this account number.";
                    }
                } catch (PDOException $e) {
                    $update_error = "Error creating client: " . $e->getMessage();
                }
            }
        } elseif ($action === 'add_client_asset') {
            $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
            $asset_type = (isset($_POST['asset_type']) && $_POST['asset_type'] === 'Software') ? 'Software' : 'Hardware';
            $serial_number = isset($_POST['serial_number']) ? trim($_POST['serial_number']) : '';
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
            $unit_price = isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : 0;
            $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
            if ($quantity < 1) $quantity = 1;
            $w_status = (isset($_POST['warranty_status']) && $_POST['warranty_status'] === 'Active') ? 'Active' : 'Inactive';
            $w_start = isset($_POST['warranty_start']) && !empty($_POST['warranty_start']) ? trim($_POST['warranty_start']) : null;
            $w_expiry = isset($_POST['warranty_expiry']) && !empty($_POST['warranty_expiry']) ? trim($_POST['warranty_expiry']) : null;
            $w_notes = isset($_POST['warranty_notes']) ? trim($_POST['warranty_notes']) : '';

            $item_id = null;
            $item_code = null;
            $asset_name = '';

            if ($asset_type === 'Software') {
                // Software is free text - no inventory catalogue behind it
                $asset_name = isset($_POST['software_name']) ? trim($_POST['software_name']) : '';
                $quantity = 1;
                $serial_number = '';
            } else {
                // Hardware is picked from the support_inventory_items catalogue
                $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
                if ($item_id > 0) {
                    $stmt_item = $pdo->prepare("SELECT id, item_code, name, quantity, qty_good FROM support_inventory_items WHERE id = :id LIMIT 1");
                    $stmt_item->execute(array(':id' => $item_id));
                    $inv_item = $stmt_item->fetch();
                    if ($inv_item) {
                        $item_code = $inv_item['item_code'];
                        $asset_name = $inv_item['name'];
                    } else {
                        $item_id = null;
                    }
                } else {
                    $item_id = null;
                }
            }

            // Handing hardware to a client takes it out of the warehouse, so the
            // stock has to be there before anything is written.
            $good_on_hand = ($asset_type === 'Hardware' && $item_id !== null && isset($inv_item['qty_good'])) ? intval($inv_item['qty_good']) : 0;

            if (empty($accountnum)) {
                $update_error = "No client account selected.";
            } elseif ($asset_type === 'Software' && $asset_name === '') {
                $update_error = "Please enter the software name.";
            } elseif ($asset_type === 'Hardware' && $item_id === null) {
                $update_error = "Please choose a hardware item from the inventory list.";
            } elseif ($asset_type === 'Hardware' && $quantity > $good_on_hand) {
                $update_error = "Cannot release " . $quantity . " unit(s) of \"" . $asset_name . "\": only " . $good_on_hand .
                    " in Good / Functional stock. Restock or re-classify the units in Inventory first.";
            } else {
                try {
                    $now = date('Y-m-d H:i:s');
                    $tech_now = get_logged_tech();
                    $recorded_by = ($tech_now && isset($tech_now['fullname'])) ? $tech_now['fullname'] : 'Support Tech';

                    // Recording hardware, deducting the stock and raising the work
                    // order belong together - none of them should land on its own.
                    $pdo->beginTransaction();

                    $stmt_asset = $pdo->prepare("INSERT INTO client_assets
                        (accountnum, asset_type, item_id, item_code, name, serial_number, quantity, 
                         unit_price, total_amount, notes, warranty_status, warranty_start, warranty_expiry, warranty_notes, 
                         recorded_by, created_at, updated_at) 
                        VALUES 
                        (:acct, :atype, :iid, :icode, :name, :serial, :qty, 
                         :price, :total, :notes, :w_status, :w_start, :w_expiry, :w_notes, 
                         :by, :created, :updated)");

                    $stmt_asset->execute(array(
                        ':acct' => $accountnum,
                        ':atype' => $asset_type,
                        ':iid' => $item_id,
                        ':icode' => $item_code,
                        ':name' => $asset_name,
                        ':serial' => $serial_number,
                        ':qty' => $quantity,
                        ':price' => $unit_price,
                        ':total' => ($unit_price * $quantity),
                        ':notes' => $notes,
                        ':w_status' => $w_status,
                        ':w_start' => $w_start,
                        ':w_expiry' => $w_expiry,
                        ':w_notes' => $w_notes,
                        ':by' => $recorded_by,
                        ':created' => $now,
                        ':updated' => $now
                    ));

                    $update_msg = "$asset_type \"$asset_name\" recorded for Account #$accountnum.";

                    // Client details for the work order (and the movement log)
                    $wo_clientname = '';
                    $wo_address = '';
                    $stmt_cli = $pdo->prepare("SELECT tradename, clientname, address FROM bucket_client WHERE accountnum = :acct LIMIT 1");
                    $stmt_cli->execute(array(':acct' => $accountnum));
                    $cli_row = $stmt_cli->fetch();
                    if ($cli_row) {
                        $wo_clientname = !empty($cli_row['tradename']) ? $cli_row['tradename'] : $cli_row['clientname'];
                        $wo_address = isset($cli_row['address']) ? $cli_row['address'] : '';
                    }
                    if ($wo_clientname === '') {
                        $wo_clientname = 'Account #' . $accountnum;
                    }

                    // Hardware handed over also leaves the warehouse, so its stock
                    // is deducted and the movement logged before the billing.
                    $stock_note = '';
                    if ($asset_type === 'Hardware' && $item_id !== null) {
                        $prev_qty = intval($inv_item['quantity']);

                        $stmt_deduct = $pdo->prepare("UPDATE support_inventory_items
                            SET qty_good = qty_good - :amt, updated_at = :now
                            WHERE id = :id");
                        $stmt_deduct->execute(array(':amt' => $quantity, ':now' => $now, ':id' => $item_id));
                        $new_qty = resync_item_total_quantity($pdo, $item_id);
                        $stock_note = ' ' . $quantity . ' unit(s) deducted from inventory (' . $new_qty . ' left in stock) and';

                        $log_notes = 'Released to client via Software & Hardware record';
                        if ($serial_number !== '') {
                            $log_notes .= ' | S/N: ' . $serial_number;
                        }
                        if ($notes !== '') {
                            $log_notes .= ' | Notes: ' . $notes;
                        }

                        $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs
                            (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, accountnum, client_name, notes, created_at)
                            VALUES (:item_id, :tech, 'Deploy to Client', :change, :prev, :new, :acct, :client, :notes, :now)");
                        $stmt_log->execute(array(
                            ':item_id' => $item_id,
                            ':tech' => $recorded_by,
                            ':change' => -$quantity,
                            ':prev' => $prev_qty,
                            ':new' => $new_qty,
                            ':acct' => $accountnum,
                            ':client' => $wo_clientname,
                            ':notes' => $log_notes,
                            ':now' => $now
                        ));
                    }

                    // Anything handed to a client gets billed, software included:
                    // raise an unpaid work order the tech only has to mark Paid.
                    $wo_nature = ($quantity > 1) ? ($quantity . 'x ' . $asset_name) : $asset_name;
                    // Some items carry their name as the code; no point printing it twice
                    if (!empty($item_code) && strcasecmp(trim($item_code), trim($asset_name)) !== 0) {
                        $wo_nature .= ' (' . $item_code . ')';
                    }
                    if ($serial_number !== '') {
                        $wo_nature .= ' - S/N: ' . $serial_number;
                    }

                    $stmt_wo_new = $pdo->prepare("INSERT INTO bucket_workorder
                        (accountnum, xdate, clientname, address, natureofwork, amount, status, ornum)
                        VALUES (:acct, :xdate, :cname, :addr, :nature, :amount, 'unpaid', '')");
                    $stmt_wo_new->execute(array(
                        ':acct' => $accountnum,
                        ':xdate' => date('Y-m-d'),
                        ':cname' => $wo_clientname,
                        ':addr' => $wo_address,
                        ':nature' => $wo_nature,
                        ':amount' => ($unit_price * $quantity)
                    ));
                    $new_wo_id = intval($pdo->lastInsertId());

                    $update_msg = "$asset_type \"$asset_name\" recorded for Account #$accountnum." . $stock_note .
                        " Work Order #WO-" . $new_wo_id . " raised as Unpaid - open the Work Orders tab to mark it Paid once settled.";

                    $pdo->commit();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $update_error = "Error saving item: " . $e->getMessage();
                }
            }
        } elseif ($action === 'update_client_asset') {
            $asset_id = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
            $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
            $serial_number = isset($_POST['serial_number']) ? trim($_POST['serial_number']) : '';
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
            $unit_price = isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : 0;
            $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
            if ($quantity < 1) $quantity = 1;
            $w_status = (isset($_POST['warranty_status']) && $_POST['warranty_status'] === 'Active') ? 'Active' : 'Inactive';
            $w_start = isset($_POST['warranty_start']) && !empty($_POST['warranty_start']) ? trim($_POST['warranty_start']) : null;
            $w_expiry = isset($_POST['warranty_expiry']) && !empty($_POST['warranty_expiry']) ? trim($_POST['warranty_expiry']) : null;
            $w_notes = isset($_POST['warranty_notes']) ? trim($_POST['warranty_notes']) : '';

            if ($asset_id < 1) {
                $update_error = "No item selected to edit.";
            } else {
                try {
                    // Scope the lookup to the account so one client's id cannot touch another's record
                    $stmt_cur = $pdo->prepare("SELECT * FROM client_assets WHERE id = :id AND accountnum = :acct LIMIT 1");
                    $stmt_cur->execute(array(':id' => $asset_id, ':acct' => $accountnum));
                    $current = $stmt_cur->fetch();

                    if (!$current) {
                        $update_error = "That item no longer exists for this account.";
                    } else {
                        $asset_name = $current['name'];
                        $item_id = $current['item_id'];
                        $item_code = $current['item_code'];

                        if ($current['asset_type'] === 'Software') {
                            $asset_name = isset($_POST['software_name']) ? trim($_POST['software_name']) : '';
                            $quantity = 1;
                            $serial_number = '';
                        } else {
                            $new_item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
                            if ($new_item_id > 0) {
                                $stmt_item = $pdo->prepare("SELECT id, item_code, name FROM support_inventory_items WHERE id = :id LIMIT 1");
                                $stmt_item->execute(array(':id' => $new_item_id));
                                $inv_item = $stmt_item->fetch();
                                if ($inv_item) {
                                    $item_id = $inv_item['id'];
                                    $item_code = $inv_item['item_code'];
                                    $asset_name = $inv_item['name'];
                                }
                            }
                        }

                        if ($current['asset_type'] === 'Software' && $asset_name === '') {
                            $update_error = "Please enter the software name.";
                        } else {
                            $stmt_up = $pdo->prepare("UPDATE client_assets
                                SET item_id = :iid,
                                    item_code = :icode,
                                    name = :name,
                                    serial_number = :serial,
                                    quantity = :qty,
                                    unit_price = :price,
                                    total_amount = :total,
                                    notes = :notes,
                                    warranty_status = :w_status,
                                    warranty_start = :w_start,
                                    warranty_expiry = :w_expiry,
                                    warranty_notes = :w_notes,
                                    updated_at = :updated
                                WHERE id = :id AND accountnum = :acct");

                            $stmt_up->execute(array(
                                ':iid' => $item_id,
                                ':icode' => $item_code,
                                ':name' => $asset_name,
                                ':serial' => $serial_number,
                                ':qty' => $quantity,
                                ':price' => $unit_price,
                                ':total' => ($unit_price * $quantity),
                                ':notes' => $notes,
                                ':w_status' => $w_status,
                                ':w_start' => $w_start,
                                ':w_expiry' => $w_expiry,
                                ':w_notes' => $w_notes,
                                ':updated' => date('Y-m-d H:i:s'),
                                ':id' => $asset_id,
                                ':acct' => $accountnum
                            ));

                            $update_msg = "\"$asset_name\" updated successfully.";
                        }
                    }
                } catch (PDOException $e) {
                    $update_error = "Error updating item: " . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_client_asset') {
            $asset_id = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
            $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';

            if ($asset_id < 1) {
                $update_error = "No item selected to delete.";
            } else {
                try {
                    $stmt_cur = $pdo->prepare("SELECT name FROM client_assets WHERE id = :id AND accountnum = :acct LIMIT 1");
                    $stmt_cur->execute(array(':id' => $asset_id, ':acct' => $accountnum));
                    $current = $stmt_cur->fetch();

                    if (!$current) {
                        $update_error = "That item no longer exists for this account.";
                    } else {
                        $stmt_del = $pdo->prepare("DELETE FROM client_assets WHERE id = :id AND accountnum = :acct");
                        $stmt_del->execute(array(':id' => $asset_id, ':acct' => $accountnum));
                        $update_msg = "\"" . $current['name'] . "\" removed from this account.";
                    }
                } catch (PDOException $e) {
                    $update_error = "Error deleting item: " . $e->getMessage();
                }
            }
        } elseif ($action === 'create_workorder') {
            $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
            $clientname = isset($_POST['clientname']) ? trim($_POST['clientname']) : '';
            $address = isset($_POST['address']) ? trim($_POST['address']) : '';
            $xdate = isset($_POST['xdate']) && !empty($_POST['xdate']) ? trim($_POST['xdate']) : date('Y-m-d');
            $natureofwork = isset($_POST['natureofwork']) ? trim($_POST['natureofwork']) : '';
            $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '0';
            $status = isset($_POST['status']) ? trim($_POST['status']) : 'paid';
            $ornum = isset($_POST['ornum']) ? trim($_POST['ornum']) : '';

            if (!empty($accountnum) && !empty($natureofwork)) {
                try {
                    if (empty($clientname) || empty($address)) {
                        $stmt_c = $pdo->prepare("SELECT tradename, clientname, address FROM bucket_client WHERE accountnum = :acct LIMIT 1");
                        $stmt_c->execute(array(':acct' => $accountnum));
                        $c_row = $stmt_c->fetch();
                        if ($c_row) {
                            if (empty($clientname)) $clientname = !empty($c_row['tradename']) ? $c_row['tradename'] : $c_row['clientname'];
                            if (empty($address)) $address = $c_row['address'];
                        }
                    }

                    $stmt_wo = $pdo->prepare("INSERT INTO bucket_workorder 
                        (accountnum, xdate, clientname, address, natureofwork, amount, status, ornum) 
                        VALUES (:acct, :xdate, :cname, :addr, :nature, :amount, :status, :ornum)");
                    $stmt_wo->execute(array(
                        ':acct' => $accountnum,
                        ':xdate' => $xdate,
                        ':cname' => $clientname,
                        ':addr' => $address,
                        ':nature' => $natureofwork,
                        ':amount' => $amount,
                        ':status' => $status,
                        ':ornum' => $ornum
                    ));

                    $update_msg = "Work order created and recorded successfully!";
                } catch (PDOException $e) {
                    $update_error = "Error creating work order: " . $e->getMessage();
                }
            } else {
                $update_error = "Account Number and Nature of Work are required.";
            }
        } elseif ($action === 'update_workorder') {
            $wo_id = isset($_POST['wo_id']) ? intval($_POST['wo_id']) : 0;
            $clientname = isset($_POST['clientname']) ? trim($_POST['clientname']) : '';
            $address = isset($_POST['address']) ? trim($_POST['address']) : '';
            $xdate = isset($_POST['xdate']) ? trim($_POST['xdate']) : date('Y-m-d');
            $natureofwork = isset($_POST['natureofwork']) ? trim($_POST['natureofwork']) : '';
            $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '0';
            $status = isset($_POST['status']) ? trim($_POST['status']) : 'paid';
            $ornum = isset($_POST['ornum']) ? trim($_POST['ornum']) : '';

            if ($wo_id > 0 && !empty($natureofwork)) {
                try {
                    $stmt_up = $pdo->prepare("UPDATE bucket_workorder 
                        SET clientname = :cname, address = :addr, xdate = :xdate, natureofwork = :nature, amount = :amount, status = :status, ornum = :ornum 
                        WHERE id = :id");
                    $stmt_up->execute(array(
                        ':cname' => $clientname,
                        ':addr' => $address,
                        ':xdate' => $xdate,
                        ':nature' => $natureofwork,
                        ':amount' => $amount,
                        ':status' => $status,
                        ':ornum' => $ornum,
                        ':id' => $wo_id
                    ));
                    $update_msg = "Work Order #WO-$wo_id updated successfully!";
                } catch (PDOException $e) {
                    $update_error = "Error updating work order: " . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_workorder') {
            $wo_id = isset($_POST['wo_id']) ? intval($_POST['wo_id']) : 0;
            if ($wo_id > 0) {
                try {
                    $stmt_del = $pdo->prepare("DELETE FROM bucket_workorder WHERE id = :id");
                    $stmt_del->execute(array(':id' => $wo_id));
                    $update_msg = "Work Order #WO-$wo_id deleted successfully.";
                } catch (PDOException $e) {
                    $update_error = "Error deleting work order: " . $e->getMessage();
                }
            }
        }
    }
}

// Search & Selected Account logic
$search = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['account']) ? trim($_GET['account']) : (isset($_GET['search']) ? trim($_GET['search']) : (isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '')));
$active_tab = isset($_GET['tab']) ? trim($_GET['tab']) : (isset($_GET['q']) || isset($_GET['account']) || isset($_GET['search']) ? 'logs' : 'orders');
if (isset($_POST['action']) && in_array($_POST['action'], array('create_workorder', 'update_workorder', 'delete_workorder'))) {
    $active_tab = 'orders';
}
if (isset($_POST['action']) && in_array($_POST['action'], array('add_client_asset', 'update_client_asset', 'delete_client_asset'))) {
    $active_tab = 'assets';
}

$selected_client = null;
$searched = !empty($search);

if ($searched) {
    // Exact or LIKE match in bucket_client
    $stmt_c = $pdo->prepare("SELECT * FROM bucket_client 
        WHERE accountnum = :exact_acct OR accountnum LIKE :q OR LOWER(tradename) LIKE :q OR LOWER(clientname) LIKE :q LIMIT 1");
    $stmt_c->execute(array(
        ':exact_acct' => $search,
        ':q' => '%' . strtolower($search) . '%'
    ));
    $selected_client = $stmt_c->fetch();
}

// Global master data when no client is selected
$global_wo_list = array();
$global_wo_summary = array('total_cnt' => 0, 'total_amt' => 0.0, 'paid_amt' => 0.0, 'unpaid_amt' => 0.0);
$global_wo_status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$global_wo_q = isset($_GET['wo_q']) ? trim($_GET['wo_q']) : '';

$global_notes_list = array();
$global_notes_q = isset($_GET['note_q']) ? trim($_GET['note_q']) : '';

$global_logs_list = array();
$global_logs_q = isset($_GET['log_q']) ? trim($_GET['log_q']) : '';

if (!$selected_client) {
    if ($active_tab === 'orders' || $active_tab === 'workorders') {
        try {
            $stmt_gwo_sum = $pdo->query("SELECT 
                COUNT(*) as total_cnt,
                COALESCE(SUM(amount), 0) as total_amt,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'paid' THEN amount ELSE 0 END), 0) as paid_amt,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) != 'paid' THEN amount ELSE 0 END), 0) as unpaid_amt
                FROM bucket_workorder");
            if ($stmt_gwo_sum) {
                $global_wo_summary = $stmt_gwo_sum->fetch(PDO::FETCH_ASSOC);
            }

            $gwo_sql = "SELECT w.*, c.tradename, c.clientname as cl_owner 
                FROM bucket_workorder w 
                LEFT JOIN bucket_client c ON w.accountnum = c.accountnum 
                WHERE 1=1 ";
            $gwo_p = array();

            if ($global_wo_status === 'paid') {
                $gwo_sql .= " AND LOWER(TRIM(w.status)) = 'paid' ";
            } elseif ($global_wo_status === 'unpaid' || $global_wo_status === 'pending') {
                $gwo_sql .= " AND LOWER(TRIM(w.status)) != 'paid' ";
            }

            if (!empty($global_wo_q)) {
                $gwo_sql .= " AND (w.id LIKE :kw OR w.accountnum LIKE :kw OR LOWER(w.natureofwork) LIKE :kw OR LOWER(w.ornum) LIKE :kw OR LOWER(c.tradename) LIKE :kw OR LOWER(c.clientname) LIKE :kw) ";
                $gwo_p[':kw'] = '%' . strtolower($global_wo_q) . '%';
            }

            $gwo_sql .= " ORDER BY w.xdate DESC, w.id DESC LIMIT 150 ";
            $stmt_gwo = $pdo->prepare($gwo_sql);
            $stmt_gwo->execute($gwo_p);
            $global_wo_list = $stmt_gwo->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Global WO Error: " . $e->getMessage());
        }
    } elseif ($active_tab === 'notes' || $active_tab === 'technotes') {
        try {
            $n_sql = "SELECT n.*, c.tradename, c.clientname as cl_owner 
                FROM bucket_technotes n 
                LEFT JOIN bucket_client c ON n.accountnum = c.accountnum 
                WHERE 1=1 ";
            $n_p = array();
            if (!empty($global_notes_q)) {
                $n_sql .= " AND (n.accountnum LIKE :kw OR LOWER(n.techname) LIKE :kw OR LOWER(n.reasonoftech) LIKE :kw OR LOWER(n.solutionoftech) LIKE :kw OR LOWER(c.tradename) LIKE :kw) ";
                $n_p[':kw'] = '%' . strtolower($global_notes_q) . '%';
            }
            $n_sql .= " ORDER BY n.xdate DESC, n.id DESC LIMIT 150 ";
            $stmt_gn = $pdo->prepare($n_sql);
            $stmt_gn->execute($n_p);
            $global_notes_list = $stmt_gn->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    } elseif ($active_tab === 'logs') {
        try {
            $l_sql = "SELECT l.*, c.tradename, c.clientname as cl_owner 
                FROM hardware_troubleshooting_logs l 
                LEFT JOIN bucket_client c ON l.accountnum = c.accountnum 
                WHERE 1=1 ";
            $l_p = array();
            if (!empty($global_logs_q)) {
                $l_sql .= " AND (l.accountnum LIKE :kw OR LOWER(l.hardware_selected) LIKE :kw OR LOWER(l.issue_selected) LIKE :kw OR LOWER(c.tradename) LIKE :kw) ";
                $l_p[':kw'] = '%' . strtolower($global_logs_q) . '%';
            }
            $l_sql .= " ORDER BY l.created_at DESC LIMIT 150 ";
            $stmt_gl = $pdo->prepare($l_sql);
            $stmt_gl->execute($l_p);
            $global_logs_list = $stmt_gl->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }
}

// Fetch top quick clients


// Retain "Add New Client" form input when a submission is rejected, so nothing typed is lost
$cf = (!empty($update_error) && isset($_POST['action']) && $_POST['action'] === 'create_client') ? $_POST : array();
function cf_val($cf, $key, $default = '') {
    return isset($cf[$key]) ? $cf[$key] : $default;
}
function cf_sel($cf, $key, $option, $default) {
    $current = isset($cf[$key]) ? $cf[$key] : $default;
    return ($current === $option) ? ' selected' : '';
}
// Suggest an unused account number for the "Add New Client" form.
// Existing accounts are random 7-8 digit numbers rather than a sequence,
// so generate a random 8-digit value and confirm it is free.
$next_accountnum = '';
try {
    $stmt_free = $pdo->prepare("SELECT id FROM bucket_client WHERE accountnum = :acct LIMIT 1");
    for ($try = 0; $try < 10; $try++) {
        $candidate = str_pad(mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);
        $stmt_free->execute(array(':acct' => $candidate));
        if (!$stmt_free->fetch()) {
            $next_accountnum = $candidate;
            break;
        }
    }
} catch (PDOException $e) {
    $next_accountnum = '';
}
// Quick Accounts: surface the most active clients first (most support tickets
// or tech notes submitted), falling back to plain account order to fill out
// the remaining slots if too few accounts have any activity yet.
$stmt_quick = $pdo->query("SELECT bc.accountnum, bc.tradename, bc.clientname,
        (COALESCE(t.ticket_cnt, 0) + COALESCE(n.note_cnt, 0)) AS activity_cnt
    FROM bucket_client bc
    LEFT JOIN (SELECT accountnum, COUNT(*) AS ticket_cnt FROM client_support_tickets GROUP BY accountnum) t
        ON t.accountnum = bc.accountnum
    LEFT JOIN (SELECT accountnum, COUNT(*) AS note_cnt FROM bucket_technotes GROUP BY accountnum) n
        ON n.accountnum = bc.accountnum
    ORDER BY activity_cnt DESC, bc.id ASC
    LIMIT 6");
$quick_clients = $stmt_quick->fetchAll();

// Data queries for active tabs if client selected
$client_acct = $selected_client ? $selected_client['accountnum'] : '';
$hw_logs = array();
$tech_notes = array();
$work_orders = array();

$client_pullouts = array();
$client_assets = array();
$spend_wo = array('n' => 0, 'total' => 0, 'paid' => 0, 'unpaid' => 0, 'first_date' => null, 'last_date' => null);
$spend_orders = array('n' => 0, 'total' => 0, 'paid' => 0);
$spend_assets = array('n' => 0, 'total' => 0);
$spend_billed = 0; $spend_paid = 0; $spend_unpaid = 0; $spend_txns = 0; $spend_avg = 0;

// Active inventory items, used as the hardware picker in the "Add Item" modal
$stmt_inv = $pdo->query("SELECT id, item_code, name, category, quantity, selling_price, unit_price 
    FROM support_inventory_items WHERE status = 'Active' ORDER BY name ASC");
$inventory_items = $stmt_inv ? $stmt_inv->fetchAll() : array();

if ($selected_client) {
    // 1. Hardware Logs for this account
    $stmt_hw = $pdo->prepare("SELECT * FROM hardware_troubleshooting_logs WHERE accountnum = :acct ORDER BY created_at DESC");
    $stmt_hw->execute(array(':acct' => $client_acct));
    $hw_logs = $stmt_hw->fetchAll();

    // 2. Service Notes for this account
    $stmt_notes = $pdo->prepare("SELECT * FROM bucket_technotes WHERE accountnum = :acct ORDER BY id DESC");
    $stmt_notes->execute(array(':acct' => $client_acct));
    $tech_notes = $stmt_notes->fetchAll();

    // 3. Work Orders for this account
    $stmt_wo = $pdo->prepare("SELECT * FROM bucket_workorder WHERE accountnum = :acct ORDER BY id DESC");
    $stmt_wo->execute(array(':acct' => $client_acct));
    $work_orders = $stmt_wo->fetchAll();

    // 4. Hardware Pull-Outs for this account
    $stmt_pullouts = $pdo->prepare("SELECT l.*, i.name as item_name, i.item_code 
        FROM support_inventory_logs l 
        LEFT JOIN support_inventory_items i ON l.item_id = i.id 
        WHERE l.accountnum = :acct AND (l.change_type LIKE '%Pull Out%' OR l.change_type LIKE '%Pull-Out%') 
        ORDER BY l.created_at DESC");
    $stmt_pullouts->execute(array(':acct' => $client_acct));
    $client_pullouts = $stmt_pullouts->fetchAll();

    // 5. Lifetime spend for this account, kept split by source so nothing is
    //    silently double counted between work orders, orders and equipment.
    //    Super Admin (Master) only - not queried at all for anyone else.
    if ($can_view_spend) {
    $stmt_wo_spend = $pdo->prepare("SELECT
            COUNT(*) AS n,
            COALESCE(SUM(amount), 0) AS total,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'paid' THEN amount ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) <> 'paid' THEN amount ELSE 0 END), 0) AS unpaid,
            MIN(xdate) AS first_date,
            MAX(xdate) AS last_date
        FROM bucket_workorder WHERE accountnum = :acct");
    $stmt_wo_spend->execute(array(':acct' => $client_acct));
    $spend_wo = $stmt_wo_spend->fetch();

    $stmt_ord_spend = $pdo->prepare("SELECT
            COUNT(*) AS n,
            COALESCE(SUM(total_amount), 0) AS total,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) IN ('paid','completed','delivered','fulfilled') THEN total_amount ELSE 0 END), 0) AS paid
        FROM client_hardware_orders
        WHERE accountnum = :acct AND LOWER(TRIM(status)) <> 'cancelled'");
    $stmt_ord_spend->execute(array(':acct' => $client_acct));
    $spend_orders = $stmt_ord_spend->fetch();

    $stmt_asset_spend = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS total
        FROM client_assets WHERE accountnum = :acct");
    $stmt_asset_spend->execute(array(':acct' => $client_acct));
    $spend_assets = $stmt_asset_spend->fetch();

    $spend_billed = floatval($spend_wo['total']) + floatval($spend_orders['total']);
    $spend_paid   = floatval($spend_wo['paid']) + floatval($spend_orders['paid']);
    $spend_unpaid = max(0, $spend_billed - $spend_paid);
    $spend_txns   = intval($spend_wo['n']) + intval($spend_orders['n']);
    $spend_avg    = ($spend_txns > 0) ? ($spend_billed / $spend_txns) : 0;
    }

    // 6. Recorded Software & Hardware owned by this account
    $stmt_assets = $pdo->prepare("SELECT * FROM client_assets WHERE accountnum = :acct ORDER BY id DESC");
    $stmt_assets->execute(array(':acct' => $client_acct));
    $client_assets = $stmt_assets->fetchAll();
}

// Fetch ALL client accounts for instant autocomplete dropdown
$stmt_all_accts = $pdo->query("SELECT accountnum, tradename, clientname FROM bucket_client ORDER BY tradename ASC");
$all_accounts_list = $stmt_all_accts->fetchAll();

$my_tier = get_logged_tech_access_tier();

$active_page = 'accounts';
$page_title = 'Manage Accounts';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts - RNZ Admin</title>
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
        const ALL_ACCOUNTS = <?php echo json_encode($all_accounts_list); ?>;
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

        <main class="p-6 sm:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Notification Alerts -->
            <?php if (!empty($update_msg)): ?>
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold flex items-center justify-between shadow-sm">
                    <span class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <?php echo sanitize($update_msg); ?>
                    </span>
                    <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-800">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($update_error)): ?>
                <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold flex items-center justify-between shadow-sm">
                    <span class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        <?php echo sanitize($update_error); ?>
                    </span>
                    <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-800">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Prominent Account Search Bar -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <div class="inline-flex items-center space-x-2 bg-[#FFE8D5] px-3 py-1 rounded-full text-[#EB3E0B] text-xs font-bold uppercase tracking-wider mb-2">
                            <span>Account Management Hub</span>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900">Manage Client Accounts</h2>
                        <p class="text-xs text-slate-500 mt-1">Search an account below to view/edit client profile details, diagnostic logs, service notes, and work orders.</p>
                    </div>
                    <?php if ($my_tier >= 2): ?>
                        <button type="button" onclick="openCreateClientModal()" class="shrink-0 inline-flex items-center justify-center space-x-2 bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-5 py-2.5 rounded-full shadow-md transition-all active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Add New Client</span>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Search Input Form with Live Autocomplete -->
                <form action="accounts.php" method="GET" class="flex flex-col sm:flex-row items-center gap-3">
                    <div class="relative flex-1 w-full">
                        <input type="text" 
                               id="accountSearchInput"
                               name="q" 
                               value="<?php echo sanitize($search); ?>" 
                               required 
                               autocomplete="off"
                               placeholder="Type Account # (e.g. 00000002) or Client Business Name..." 
                               class="w-full bg-slate-50 text-slate-900 text-sm pl-11 pr-4 py-3.5 rounded-2xl border border-slate-200 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all placeholder-slate-400 font-medium"
                               oninput="handleAccountSearchInput(this.value)"
                               onfocus="handleAccountSearchInput(this.value)">
                        <svg class="w-5 h-5 text-slate-400 absolute left-3.5 top-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>

                        <!-- INSTANT LIVE AUTOCOMPLETE DROPDOWN -->
                        <div id="accountAutocompleteDropdown" 
                             class="absolute left-0 right-0 top-full mt-2 bg-white rounded-2xl border border-slate-200 shadow-2xl z-50 hidden max-h-72 overflow-y-auto divide-y divide-slate-100 animate-fadeIn">
                        </div>
                    </div>

                    <button type="submit" class="w-full sm:w-auto bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-8 py-3.5 rounded-2xl shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95 flex items-center justify-center space-x-2 shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <span>Search Account</span>
                    </button>

                    <?php if ($searched): ?>
                        <a href="accounts.php" class="w-full sm:w-auto bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs sm:text-sm px-5 py-3.5 rounded-2xl transition-all text-center shrink-0">
                            Clear Search
                        </a>
                    <?php endif; ?>
                </form>

                <!-- Quick Client Suggestions -->
                <?php if (!empty($quick_clients)): ?>
                    <div class="pt-1 flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-slate-400 font-bold text-[11px] uppercase tracking-wider">Quick Accounts:</span>
                        <?php foreach ($quick_clients as $qc):
                            $qc_label = !empty($qc['tradename']) ? $qc['tradename'] : $qc['clientname'];
                            $qc_activity = isset($qc['activity_cnt']) ? intval($qc['activity_cnt']) : 0;
                        ?>
                            <a href="accounts.php?q=<?php echo urlencode($qc['accountnum']); ?>"
                               class="inline-flex items-center gap-1.5 bg-slate-100 hover:bg-[#FFE8D5] text-slate-700 hover:text-[#EB3E0B] px-3 py-1 rounded-full border border-slate-200/80 font-mono transition-colors">
                                <span><?php echo sanitize($qc['accountnum']); ?> (<?php echo sanitize($qc_label); ?>)</span>
                                <?php if ($qc_activity > 0): ?>
                                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-white/70 text-slate-500" title="<?php echo $qc_activity; ?> ticket(s) + tech note(s) submitted">
                                        <?php echo $qc_activity; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- INITIAL STATE: NO ACCOUNT SEARCHED OR MASTER VIEW SELECTED -->
            <?php if (!$searched || !$selected_client): ?>
                
                <?php if ($searched && !$selected_client): ?>
                    <!-- Account Not Found Alert -->
                    <div class="bg-white rounded-3xl p-12 border border-rose-200 shadow-sm text-center space-y-4">
                        <div class="w-20 h-20 rounded-full bg-rose-50 text-rose-500 flex items-center justify-center mx-auto shadow-inner">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-lg font-extrabold text-slate-900">Account Not Found</h3>
                            <p class="text-xs text-slate-500 max-w-md mx-auto">No client account matched your search <strong>"<?php echo sanitize($search); ?>"</strong>. Please try another account number or trade name.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Global Master Hub Navigation Tabs (When no specific client is opened) -->
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3">
                    <a href="accounts.php?tab=orders" class="px-4 py-2.5 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 <?php echo ($active_tab === 'orders' || $active_tab === 'workorders') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200'; ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        <span>All Work Orders</span>
                        <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-mono <?php echo ($active_tab === 'orders' || $active_tab === 'workorders') ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'; ?>">
                            <?php echo number_format($global_wo_summary['total_cnt']); ?>
                        </span>
                    </a>
                    <a href="accounts.php?tab=notes" class="px-4 py-2.5 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 <?php echo ($active_tab === 'notes' || $active_tab === 'technotes') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200'; ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <span>All Service Notes</span>
                    </a>
                    <a href="accounts.php?tab=logs" class="px-4 py-2.5 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 <?php echo ($active_tab === 'logs' && !$searched) ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200'; ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        <span>All Diagnostic Logs</span>
                    </a>
                    <a href="accounts.php?tab=clients" class="px-4 py-2.5 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 <?php echo ($active_tab === 'clients') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200'; ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <span>Search by Account</span>
                    </a>
                </div>

                <!-- 1. MASTER WORK ORDERS VIEW -->
                <?php if ($active_tab === 'orders' || $active_tab === 'workorders'): ?>
                    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
                        
                        <!-- Top Summary Statistics (revenue figures: Super Admin / Master only) -->
                        <?php if ($can_view_spend): ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-1">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Total Work Orders</span>
                                <p class="font-mono text-2xl font-extrabold text-slate-900"><?php echo number_format($global_wo_summary['total_cnt']); ?></p>
                                <span class="text-[11px] text-slate-500">Across all registered accounts</span>
                            </div>
                            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-1">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Total Billed Revenue</span>
                                <p class="font-mono text-2xl font-black text-slate-900">&#8369;<?php echo number_format(floatval($global_wo_summary['total_amt']), 2); ?></p>
                                <span class="text-[11px] text-emerald-600 font-bold">Service &amp; Maintenance</span>
                            </div>
                            <div class="p-4 rounded-2xl bg-emerald-50/60 border border-emerald-200 space-y-1">
                                <span class="text-[10px] font-bold text-emerald-800 uppercase tracking-wider block">Settled Collections</span>
                                <p class="font-mono text-2xl font-black text-emerald-700">&#8369;<?php echo number_format(floatval($global_wo_summary['paid_amt']), 2); ?></p>
                                <span class="text-[11px] text-emerald-700 font-bold">Paid Work Orders</span>
                            </div>
                            <div class="p-4 rounded-2xl bg-amber-50/60 border border-amber-200 space-y-1">
                                <span class="text-[10px] font-bold text-amber-800 uppercase tracking-wider block">Pending Receivables</span>
                                <p class="font-mono text-2xl font-black text-amber-700">&#8369;<?php echo number_format(floatval($global_wo_summary['unpaid_amt']), 2); ?></p>
                                <span class="text-[11px] text-amber-800 font-bold">Unpaid / In Progress</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Filter & Search Toolbar -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2 border-t border-slate-100">
                            <div class="flex items-center space-x-1.5 bg-slate-100 p-1 rounded-2xl">
                                <a href="accounts.php?tab=orders&status=all<?php echo !empty($global_wo_q) ? '&wo_q=' . urlencode($global_wo_q) : ''; ?>" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($global_wo_status === 'all') ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'; ?>">
                                    All (<?php echo count($global_wo_list); ?>)
                                </a>
                                <a href="accounts.php?tab=orders&status=paid<?php echo !empty($global_wo_q) ? '&wo_q=' . urlencode($global_wo_q) : ''; ?>" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($global_wo_status === 'paid') ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'; ?>">
                                    Paid
                                </a>
                                <a href="accounts.php?tab=orders&status=unpaid<?php echo !empty($global_wo_q) ? '&wo_q=' . urlencode($global_wo_q) : ''; ?>" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($global_wo_status === 'unpaid' || $global_wo_status === 'pending') ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'; ?>">
                                    Pending / Unpaid
                                </a>
                            </div>

                            <form method="GET" action="accounts.php" class="flex items-center space-x-2 w-full sm:w-auto">
                                <input type="hidden" name="tab" value="orders">
                                <input type="hidden" name="status" value="<?php echo sanitize($global_wo_status); ?>">
                                <div class="relative w-full sm:w-72">
                                    <input type="text" name="wo_q" value="<?php echo sanitize($global_wo_q); ?>" placeholder="Search WO#, client, OR#, scope..." class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs pl-8 pr-3 py-2 rounded-xl focus:outline-none focus:border-[#EB3E0B] font-medium">
                                    <svg class="w-4 h-4 text-slate-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                </div>
                                <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-xs font-bold px-3.5 py-2 rounded-xl transition-all">Search</button>
                                <?php if (!empty($global_wo_q) || $global_wo_status !== 'all'): ?>
                                    <a href="accounts.php?tab=orders" class="bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold px-2.5 py-2 rounded-xl transition-all">Clear</a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <!-- Work Orders Master Table -->
                        <div class="overflow-x-auto rounded-2xl border border-slate-200">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px] border-b border-slate-200">
                                        <th class="py-3 px-4">WO Ref</th>
                                        <th class="py-3 px-4">Date</th>
                                        <th class="py-3 px-4">Client Business</th>
                                        <th class="py-3 px-4">Scope of Work</th>
                                        <th class="py-3 px-4 text-center">Status</th>
                                        <?php if ($can_view_spend): ?>
                                            <th class="py-3 px-4 text-right">Amount (PHP)</th>
                                        <?php endif; ?>
                                        <th class="py-3 px-4 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!empty($global_wo_list)): ?>
                                        <?php foreach ($global_wo_list as $wo): ?>
                                            <?php 
                                            $is_wo_paid = (strtolower(trim($wo['status'])) === 'paid');
                                            $cl_name = !empty($wo['tradename']) ? $wo['tradename'] : (!empty($wo['clientname']) ? $wo['clientname'] : 'Acct #' . $wo['accountnum']);
                                            ?>
                                            <tr class="hover:bg-slate-50/80 transition-colors">
                                                <td class="py-3 px-4 font-mono font-bold text-slate-900">
                                                    WO-<?php echo str_pad($wo['id'], 6, '0', STR_PAD_LEFT); ?>
                                                </td>
                                                <td class="py-3 px-4 font-mono text-slate-500">
                                                    <?php echo format_date_only($wo['xdate']); ?>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <a href="accounts.php?q=<?php echo urlencode($wo['accountnum']); ?>&tab=orders" class="font-bold text-slate-900 hover:text-[#EB3E0B] transition-colors block">
                                                        <?php echo sanitize($cl_name); ?>
                                                    </a>
                                                    <span class="font-mono text-[10px] text-slate-400">#<?php echo sanitize($wo['accountnum']); ?></span>
                                                </td>
                                                <td class="py-3 px-4 text-slate-700 max-w-xs truncate" title="<?php echo sanitize($wo['natureofwork']); ?>">
                                                    <?php echo sanitize($wo['natureofwork']); ?>
                                                </td>
                                                <td class="py-3 px-4 text-center">
                                                    <?php if ($is_wo_paid): ?>
                                                        <span class="inline-flex items-center gap-1 bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2.5 py-0.5 rounded-full border border-emerald-200">
                                                            Paid <?php echo !empty($wo['ornum']) ? '&bull; OR #' . sanitize($wo['ornum']) : ''; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-800 text-[10px] font-bold px-2.5 py-0.5 rounded-full border border-amber-200">
                                                            Pending
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($can_view_spend): ?>
                                                    <td class="py-3 px-4 text-right font-mono font-extrabold text-slate-900 text-sm">
                                                        &#8369;<?php echo number_format(floatval($wo['amount']), 2); ?>
                                                    </td>
                                                <?php endif; ?>
                                                <td class="py-3 px-4 text-center">
                                                    <div class="flex items-center justify-center space-x-1.5">
                                                        <a href="accounts.php?q=<?php echo urlencode($wo['accountnum']); ?>&tab=orders" class="bg-slate-100 hover:bg-[#EB3E0B] text-slate-700 hover:text-white px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all" title="Open in Client Account">
                                                            View
                                                        </a>
                                                        <a href="print_document.php?type=workorder&id=<?php echo $wo['id']; ?>" target="_blank" class="bg-slate-900 hover:bg-[#EB3E0B] text-white px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all inline-flex items-center gap-1" title="Print Work Order Statement">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                                            <span>Print</span>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo $can_view_spend ? 7 : 6; ?>" class="py-12 text-center text-slate-400">No work orders found matching your search.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>

                <!-- 2. MASTER TECHNICAL SERVICE NOTES VIEW -->
                <?php elseif ($active_tab === 'notes' || $active_tab === 'technotes'): ?>
                    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">All Technical Service Notes</h3>
                                <p class="text-xs text-slate-500">Service visits and technician field maintenance notes across all accounts</p>
                            </div>
                            <form method="GET" action="accounts.php" class="flex items-center space-x-2">
                                <input type="hidden" name="tab" value="notes">
                                <input type="text" name="note_q" value="<?php echo sanitize($global_notes_q); ?>" placeholder="Search technician, client, concern..." class="bg-slate-50 border border-slate-200 text-slate-900 text-xs px-3 py-2 rounded-xl focus:outline-none focus:border-[#EB3E0B] font-medium">
                                <button type="submit" class="bg-[#EB3E0B] text-white text-xs font-bold px-3 py-2 rounded-xl">Search</button>
                            </form>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border border-slate-200">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px] border-b border-slate-200">
                                        <th class="py-3 px-4">Ref</th>
                                        <th class="py-3 px-4">Date</th>
                                        <th class="py-3 px-4">Client Business</th>
                                        <th class="py-3 px-4">Attending Tech</th>
                                        <th class="py-3 px-4">Reason / Concern</th>
                                        <th class="py-3 px-4">Technical Solution</th>
                                        <th class="py-3 px-4 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!empty($global_notes_list)): ?>
                                        <?php foreach ($global_notes_list as $n): ?>
                                            <tr class="hover:bg-slate-50/80 transition-colors">
                                                <td class="py-3 px-4 font-mono font-bold text-slate-900">
                                                    TSN-<?php echo str_pad($n['id'], 6, '0', STR_PAD_LEFT); ?>
                                                </td>
                                                <td class="py-3 px-4 font-mono text-slate-500">
                                                    <?php echo format_date_only($n['xdate']); ?>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <a href="accounts.php?q=<?php echo urlencode($n['accountnum']); ?>&tab=notes" class="font-bold text-slate-900 hover:text-[#EB3E0B] transition-colors block">
                                                        <?php echo sanitize(!empty($n['tradename']) ? $n['tradename'] : 'Acct #' . $n['accountnum']); ?>
                                                    </a>
                                                    <span class="font-mono text-[10px] text-slate-400">#<?php echo sanitize($n['accountnum']); ?></span>
                                                </td>
                                                <td class="py-3 px-4 font-bold text-slate-800">
                                                    <?php echo sanitize($n['techname']); ?>
                                                </td>
                                                <td class="py-3 px-4 text-slate-600 max-w-xs truncate" title="<?php echo sanitize($n['reasonoftech']); ?>">
                                                    <?php echo sanitize($n['reasonoftech']); ?>
                                                </td>
                                                <td class="py-3 px-4 text-slate-800 max-w-xs truncate" title="<?php echo sanitize($n['solutionoftech']); ?>">
                                                    <?php echo sanitize($n['solutionoftech']); ?>
                                                </td>
                                                <td class="py-3 px-4 text-center">
                                                    <a href="accounts.php?q=<?php echo urlencode($n['accountnum']); ?>&tab=notes" class="bg-slate-100 hover:bg-[#EB3E0B] text-slate-700 hover:text-white px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all">
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="py-12 text-center text-slate-400">No service notes found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <!-- 3. MASTER DIAGNOSTIC LOGS VIEW -->
                <?php elseif ($active_tab === 'logs'): ?>
                    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">All Diagnostic &amp; Troubleshooting Logs</h3>
                                <p class="text-xs text-slate-500">Self-service hardware guided diagnostic trails across all accounts</p>
                            </div>
                            <form method="GET" action="accounts.php" class="flex items-center space-x-2">
                                <input type="hidden" name="tab" value="logs">
                                <input type="text" name="log_q" value="<?php echo sanitize($global_logs_q); ?>" placeholder="Search hardware, issue, client..." class="bg-slate-50 border border-slate-200 text-slate-900 text-xs px-3 py-2 rounded-xl focus:outline-none focus:border-[#EB3E0B] font-medium">
                                <button type="submit" class="bg-[#EB3E0B] text-white text-xs font-bold px-3 py-2 rounded-xl">Search</button>
                            </form>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border border-slate-200">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px] border-b border-slate-200">
                                        <th class="py-3 px-4">Log Ref</th>
                                        <th class="py-3 px-4">Date</th>
                                        <th class="py-3 px-4">Client Business</th>
                                        <th class="py-3 px-4">Hardware Tested</th>
                                        <th class="py-3 px-4">Reported Issue</th>
                                        <th class="py-3 px-4 text-center">Resolution</th>
                                        <th class="py-3 px-4 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (!empty($global_logs_list)): ?>
                                        <?php foreach ($global_logs_list as $l): ?>
                                            <tr class="hover:bg-slate-50/80 transition-colors">
                                                <td class="py-3 px-4 font-mono font-bold text-slate-900">
                                                    DIAG-<?php echo str_pad($l['id'], 6, '0', STR_PAD_LEFT); ?>
                                                </td>
                                                <td class="py-3 px-4 font-mono text-slate-500">
                                                    <?php echo format_date_only($l['created_at']); ?>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <a href="accounts.php?q=<?php echo urlencode($l['accountnum']); ?>&tab=logs" class="font-bold text-slate-900 hover:text-[#EB3E0B] transition-colors block">
                                                        <?php echo sanitize(!empty($l['tradename']) ? $l['tradename'] : 'Acct #' . $l['accountnum']); ?>
                                                    </a>
                                                    <span class="font-mono text-[10px] text-slate-400">#<?php echo sanitize($l['accountnum']); ?></span>
                                                </td>
                                                <td class="py-3 px-4 font-bold text-slate-800">
                                                    <?php echo sanitize($l['hardware_selected']); ?>
                                                </td>
                                                <td class="py-3 px-4 text-slate-600 max-w-xs truncate" title="<?php echo sanitize($l['issue_selected']); ?>">
                                                    <?php echo sanitize($l['issue_selected']); ?>
                                                </td>
                                                <td class="py-3 px-4 text-center">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                        <?php echo sanitize($l['resolution_status']); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-4 text-center">
                                                    <a href="accounts.php?q=<?php echo urlencode($l['accountnum']); ?>&tab=logs" class="bg-slate-100 hover:bg-[#EB3E0B] text-slate-700 hover:text-white px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all">
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="py-12 text-center text-slate-400">No diagnostic logs found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <!-- 4. DEFAULT SEARCH ACCOUNT VIEW -->
                <?php else: ?>
                    <div class="bg-white rounded-3xl p-12 border border-slate-200 shadow-sm text-center space-y-4">
                        <div class="w-20 h-20 rounded-full bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center mx-auto shadow-inner">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0h4m-4 0H9m4 0V7m0 0h4m-4 0H9"/>
                            </svg>
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-lg font-extrabold text-slate-900">Search a Client Account First</h3>
                            <p class="text-xs text-slate-500 max-w-md mx-auto">Please enter an account number or client business name in the search bar above to manage profile information, diagnostic logs, service notes, and work orders.</p>
                        </div>
                        <div class="pt-3 flex flex-wrap items-center justify-center gap-2">
                            <a href="accounts.php?tab=orders" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-xs font-bold px-4 py-2 rounded-2xl shadow-sm transition-all">
                                Browse All Work Orders &rarr;
                            </a>
                            <a href="accounts.php?tab=notes" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold px-4 py-2 rounded-2xl transition-all">
                                Browse All Service Notes
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>

                <!-- ACCOUNT SELECTED: PROFILE HEADER CARD -->
                <?php 
                $has_warranty = (isset($selected_client['warranty_status']) && $selected_client['warranty_status'] === 'Active');
                ?>
                <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between border-b border-slate-100 pb-6 gap-4">
                        <div class="flex items-center space-x-4">
                            <div class="w-16 h-16 rounded-3xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold text-2xl shadow-md shadow-[#EB3E0B]/25 shrink-0">
                                <?php echo strtoupper(substr($selected_client['tradename'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="flex items-center flex-wrap gap-2">
                                    <span class="text-xs font-mono font-bold bg-[#FFE8D5] text-[#EB3E0B] px-3 py-0.5 rounded-full border border-[#FECDAA]">
                                        Acct: <?php echo sanitize($selected_client['accountnum']); ?>
                                    </span>
                                    <span class="text-xs font-bold bg-slate-100 text-slate-700 px-3 py-0.5 rounded-full">
                                        <?php echo !empty($selected_client['type']) ? sanitize($selected_client['type']) : 'Client'; ?>
                                    </span>
                                    <?php if ($has_warranty): ?>
                                        <span class="text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 px-3 py-0.5 rounded-full flex items-center space-x-1 shadow-sm">
                                            <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                            </svg>
                                            <span>Active Warranty</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs font-extrabold bg-rose-100 text-rose-800 px-3 py-0.5 rounded-full border border-rose-300 shadow-sm">
                                            No Active Warranty
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h2 class="text-2xl font-extrabold text-slate-900 mt-1.5"><?php echo sanitize($selected_client['tradename']); ?></h2>
                                <p class="text-xs text-slate-500 font-medium">Owner: <strong><?php echo sanitize($selected_client['clientname']); ?></strong></p>
                            </div>
                        </div>

                        <!-- Action Buttons Group (Warranty & Profile) -->
                        <div class="flex items-center flex-wrap gap-2.5 self-start md:self-center">
                            <?php if ($my_tier >= 2): ?>
                                <!-- Add Software / Hardware Item Button -->
                                <button onclick="openAssetTypeModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm px-5 py-3 rounded-full shadow-sm transition-all active:scale-95 flex items-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    <span>Add Item</span>
                                </button>

                                <!-- Pull Out Hardware Item Button -->
                                <a href="inventory.php?pullout_client=<?php echo urlencode($selected_client['accountnum']); ?>" 
                                   class="bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-bold text-xs sm:text-sm px-5 py-3 rounded-full shadow-sm shadow-amber-500/25 transition-all active:scale-95 flex items-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                    </svg>
                                    <span>Pull Out Item</span>
                                </a>

                                <!-- Set / Edit Warranty Button -->
                                <button onclick="openWarrantyModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs sm:text-sm px-5 py-3 rounded-full shadow-sm transition-all active:scale-95 flex items-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    <span><?php echo $has_warranty ? 'Edit Warranty' : 'Set Warranty'; ?></span>
                                </button>

                                <?php if ($has_warranty): ?>
                                    <!-- Remove Warranty Button -->
                                    <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>" method="POST" onsubmit="return confirm('Are you sure you want to remove the active warranty from this account?');" class="inline">
                                        <input type="hidden" name="action" value="remove_client_warranty">
                                        <input type="hidden" name="accountnum" value="<?php echo sanitize($selected_client['accountnum']); ?>">
                                        <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-bold text-xs sm:text-sm px-4 py-3 rounded-full transition-all flex items-center space-x-1.5">
                                            <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            <span>Remove Warranty</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Edit Account Profile Button -->
                                <button onclick="openEditAccountModal()" class="bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs sm:text-sm px-5 py-3 rounded-full shadow-sm transition-all active:scale-95 flex items-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                    <span>Edit Profile</span>
                                </button>
                            <?php else: ?>
                                <span class="inline-flex items-center px-4 py-2.5 rounded-full text-xs font-bold bg-slate-100 text-slate-500 border border-slate-200">
                                    🔒 View Only Account
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Client Profile Information Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-1">
                            <span class="block text-slate-400 font-bold uppercase text-[10px]">Contact Number</span>
                            <p class="font-mono font-bold text-slate-800"><?php echo !empty($selected_client['contactnum']) ? sanitize($selected_client['contactnum']) : 'N/A'; ?></p>
                        </div>

                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-1">
                            <span class="block text-slate-400 font-bold uppercase text-[10px]">Email Address</span>
                            <p class="font-bold text-slate-800 truncate"><?php echo !empty($selected_client['emailaddress']) ? sanitize($selected_client['emailaddress']) : 'N/A'; ?></p>
                        </div>

                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-1">
                            <span class="block text-slate-400 font-bold uppercase text-[10px]">Monthly Retainer</span>
                            <p class="font-mono font-bold text-slate-800"><?php echo !empty($selected_client['monthlyretainersfee']) ? '₱' . sanitize($selected_client['monthlyretainersfee']) : 'N/A'; ?></p>
                        </div>

                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-1">
                            <span class="block text-slate-400 font-bold uppercase text-[10px]">Outstanding Balance</span>
                            <p class="font-mono font-bold text-[#EB3E0B]">₱<?php echo number_format($selected_client['outstandingbalance'], 2); ?></p>
                        </div>
                    </div>

                    <!-- Lifetime Spend Summary (Super Admin / Master accounts only) -->
                    <?php if ($can_view_spend): ?>
                    <div class="rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-900 to-slate-800 p-5 sm:p-6 space-y-5 shadow-sm">
                        <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-amber-400/90">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            <span>Super Admin Only</span>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                            <div>
                                <span class="block text-slate-400 font-bold uppercase text-[10px] tracking-wider">Total Spend With RNZ</span>
                                <p class="text-3xl sm:text-4xl font-extrabold text-white font-mono mt-1">
                                    &#8369;<?php echo number_format($spend_billed, 2); ?>
                                </p>
                                <p class="text-[11px] text-slate-400 mt-1">
                                    Across <strong class="text-slate-200"><?php echo number_format($spend_txns); ?></strong> billed transaction(s)
                                    <?php if ($spend_txns > 0): ?>
                                        &middot; averaging <strong class="text-slate-200">&#8369;<?php echo number_format($spend_avg, 2); ?></strong> each
                                    <?php endif; ?>
                                </p>
                            </div>

                            <?php if (!empty($spend_wo['first_date'])): ?>
                                <div class="text-left sm:text-right">
                                    <span class="block text-slate-400 font-bold uppercase text-[10px] tracking-wider">Client Since</span>
                                    <p class="text-sm font-bold text-slate-200 font-mono"><?php echo format_date_only($spend_wo['first_date']); ?></p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">Last billed <?php echo format_date_only($spend_wo['last_date']); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Paid vs unpaid -->
                        <?php $paid_pct = ($spend_billed > 0) ? round(($spend_paid / $spend_billed) * 100) : 0; ?>
                        <div class="space-y-2">
                            <div class="h-2 w-full bg-slate-700 rounded-full overflow-hidden flex">
                                <div class="h-full bg-emerald-500" style="width: <?php echo $paid_pct; ?>%"></div>
                                <div class="h-full bg-amber-500" style="width: <?php echo (100 - $paid_pct); ?>%"></div>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                                <span class="flex items-center gap-1.5 text-emerald-300 font-bold">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    Paid &#8369;<?php echo number_format($spend_paid, 2); ?> (<?php echo $paid_pct; ?>%)
                                </span>
                                <span class="flex items-center gap-1.5 text-amber-300 font-bold">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    Unbilled / Unpaid &#8369;<?php echo number_format($spend_unpaid, 2); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Breakdown by source -->
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 pt-1">
                            <div class="rounded-xl bg-slate-800/80 border border-slate-700 p-3">
                                <span class="block text-slate-400 font-bold uppercase text-[9px] tracking-wider">Work Orders</span>
                                <p class="text-sm font-extrabold text-white font-mono mt-0.5">&#8369;<?php echo number_format($spend_wo['total'], 2); ?></p>
                                <p class="text-[10px] text-slate-500"><?php echo number_format($spend_wo['n']); ?> record(s)</p>
                            </div>

                            <div class="rounded-xl bg-slate-800/80 border border-slate-700 p-3">
                                <span class="block text-slate-400 font-bold uppercase text-[9px] tracking-wider">Hardware Orders</span>
                                <p class="text-sm font-extrabold text-white font-mono mt-0.5">&#8369;<?php echo number_format($spend_orders['total'], 2); ?></p>
                                <p class="text-[10px] text-slate-500"><?php echo number_format($spend_orders['n']); ?> order(s)</p>
                            </div>

                            <div class="rounded-xl bg-slate-800/80 border border-slate-700 p-3">
                                <span class="block text-slate-400 font-bold uppercase text-[9px] tracking-wider">Equipment On Record</span>
                                <p class="text-sm font-extrabold text-white font-mono mt-0.5">&#8369;<?php echo number_format($spend_assets['total'], 2); ?></p>
                                <p class="text-[10px] text-slate-500"><?php echo number_format($spend_assets['n']); ?> item(s) &middot; not in total</p>
                            </div>

                            <div class="rounded-xl bg-slate-800/80 border border-slate-700 p-3">
                                <span class="block text-slate-400 font-bold uppercase text-[9px] tracking-wider">Monthly Retainer</span>
                                <p class="text-sm font-extrabold text-white font-mono mt-0.5">
                                    &#8369;<?php echo number_format(floatval($selected_client['monthlyretainersfee']), 2); ?>
                                </p>
                                <p class="text-[10px] text-slate-500">recurring</p>
                            </div>
                        </div>

                        <?php if (floatval($selected_client['outstandingbalance']) > 0): ?>
                            <div class="flex items-start gap-2 rounded-xl bg-rose-500/10 border border-rose-500/30 p-3">
                                <svg class="w-4 h-4 text-rose-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.5 0l-7.1 12.25A2 2 0 004.99 19z"/></svg>
                                <p class="text-[11px] text-rose-200 font-medium">
                                    Account carries a manually recorded outstanding balance of
                                    <strong class="font-mono text-rose-100">&#8369;<?php echo number_format(floatval($selected_client['outstandingbalance']), 2); ?></strong>.
                                    This is tracked separately from the unpaid work orders above.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Store Address & Warranty Overview Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-1">
                            <span class="block text-slate-400 font-bold uppercase text-[10px]">Physical Store Address</span>
                            <p class="font-medium text-slate-800"><?php echo !empty($selected_client['address']) ? sanitize($selected_client['address']) : 'N/A'; ?></p>
                        </div>

                        <div class="p-4 rounded-2xl <?php echo $has_warranty ? 'bg-emerald-50/90 border border-emerald-200 text-emerald-900' : 'bg-rose-50/90 border border-rose-200 text-rose-900 shadow-sm'; ?> space-y-1.5">
                            <div class="flex items-center justify-between">
                                <span class="font-bold uppercase text-[10px] tracking-wider <?php echo $has_warranty ? 'text-emerald-800' : 'text-rose-800'; ?>">
                                    🛡️ Warranty & System Protection
                                </span>
                                <span class="font-mono font-extrabold text-[11px] <?php echo $has_warranty ? 'text-emerald-700 bg-emerald-100 px-2.5 py-0.5 rounded-full border border-emerald-300' : 'text-rose-700 bg-rose-100 px-2.5 py-0.5 rounded-full border border-rose-300'; ?>">
                                    <?php echo $has_warranty ? 'ACTIVE COVERAGE' : 'INACTIVE'; ?>
                                </span>
                            </div>
                            <?php if ($has_warranty): ?>
                                <p class="font-semibold text-xs text-emerald-950">
                                    <?php echo !empty($selected_client['warranty_notes']) ? sanitize($selected_client['warranty_notes']) : 'Full Hardware & POS System Warranty Coverage'; ?>
                                </p>
                                <div class="text-[11px] text-emerald-700 font-mono">
                                    Expires: <strong><?php echo !empty($selected_client['warranty_expiry']) ? format_date($selected_client['warranty_expiry']) : 'No Expiry Date Set'; ?></strong>
                                </div>
                            <?php else: ?>
                                <p class="text-xs text-rose-800 font-medium">No active warranty assigned to this account. Click <strong>Set Warranty</strong> above to grant warranty protection.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- INTEGRATED ACCOUNT HUB TABS -->
                <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden space-y-6 p-6 sm:p-8">
                    
                    <!-- Tab Buttons Bar -->
                    <div class="flex items-center space-x-2 border-b border-slate-100 pb-4 overflow-x-auto">
                        <a href="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=logs" 
                           class="px-5 py-3 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 shrink-0 <?php echo ($active_tab === 'logs') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                            </svg>
                            <span>Diagnostic Logs (<?php echo count($hw_logs); ?>)</span>
                        </a>

                        <a href="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=notes" 
                           class="px-5 py-3 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 shrink-0 <?php echo ($active_tab === 'notes') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <span>Tech Service Notes (<?php echo count($tech_notes); ?>)</span>
                        </a>

                        <a href="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=orders" 
                           class="px-5 py-3 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 shrink-0 <?php echo ($active_tab === 'orders') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                            <span>Work Orders (<?php echo count($work_orders); ?>)</span>
                        </a>

                        <a href="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=pullouts" 
                           class="px-5 py-3 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 shrink-0 <?php echo ($active_tab === 'pullouts') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                            <span>Hardware Pull-Outs (<?php echo count($client_pullouts); ?>)</span>
                        </a>

                        <a href="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=assets" 
                           class="px-5 py-3 rounded-2xl text-xs font-extrabold transition-all flex items-center space-x-2 shrink-0 <?php echo ($active_tab === 'assets') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                            <span>Software &amp; Hardware (<?php echo count($client_assets); ?>)</span>
                        </a>
                    </div>

                    <!-- TAB 1: HARDWARE DIAGNOSTIC LOGS -->
                    <?php if ($active_tab === 'logs'): ?>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-extrabold text-slate-900">Hardware Troubleshooting Sessions</h3>
                                <span class="text-xs text-slate-400">Account #<?php echo sanitize($client_acct); ?></span>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                            <th class="py-3 px-4">Log ID</th>
                                            <th class="py-3 px-4">Hardware Device</th>
                                            <th class="py-3 px-4">Selected Issue</th>
                                            <th class="py-3 px-4">Steps Completed</th>
                                            <th class="py-3 px-4">Status</th>
                                            <th class="py-3 px-4">Timestamp</th>
                                            <th class="py-3 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 font-medium">
                                        <?php if (empty($hw_logs)): ?>
                                            <tr>
                                                <td colspan="7" class="py-8 text-center text-slate-400">No hardware troubleshooting logs found for this account.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($hw_logs as $hl): 
                                                $st_badge = get_status_badge_class($hl['resolution_status']);
                                            ?>
                                                <tr class="hover:bg-slate-50/80 transition-colors">
                                                    <td class="py-3 px-4 font-mono text-slate-400">#<?php echo $hl['id']; ?></td>
                                                    <td class="py-3 px-4 font-bold text-slate-900"><?php echo sanitize($hl['hardware_selected']); ?></td>
                                                    <td class="py-3 px-4 max-w-xs space-y-1">
                                                        <div class="font-semibold text-slate-800"><?php echo !empty($hl['issue_selected']) ? sanitize($hl['issue_selected']) : 'Diagnostic Check'; ?></div>
                                                        <?php if (!empty($hl['custom_answer'])): ?>
                                                            <div class="text-[11px] text-slate-500 italic">"<?php echo sanitize($hl['custom_answer']); ?>"</div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 font-mono font-bold text-slate-700">Step <?php echo $hl['step_completed']; ?></td>
                                                    <td class="py-3 px-4">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $st_badge; ?>">
                                                            <?php echo sanitize($hl['resolution_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-3 px-4 text-slate-500 font-mono"><?php echo format_date($hl['created_at']); ?></td>
                                                    <td class="py-3 px-4 text-right">
                                                        <a href="print_document.php?type=log&id=<?php echo $hl['id']; ?>&autoprint=1" target="_blank" 
                                                           class="p-1.5 rounded-lg bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] inline-flex items-center space-x-1 transition-colors" title="Print / Download Diagnostic Report PDF">
                                                            <svg class="w-3.5 h-3.5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                            </svg>
                                                            <span class="text-[11px] font-bold">Print</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <!-- TAB 2: TECH SERVICE NOTES (STREAMLINED VIEW DETAILS) -->
                    <?php elseif ($active_tab === 'notes'): ?>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-extrabold text-slate-900">Technical Service Notes</h3>
                                <button onclick="openNewServiceNoteModal('<?php echo addslashes($client_acct); ?>', '<?php echo addslashes($selected_client['tradename']); ?>', '<?php echo addslashes($selected_client['address']); ?>')" 
                                        class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-4 py-2 rounded-full shadow-sm flex items-center space-x-1 transition-all">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    <span>Log Service Note</span>
                                </button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                            <th class="py-3 px-4">ID / Date</th>
                                            <th class="py-3 px-4">Technician</th>
                                            <th class="py-3 px-4">Status</th>
                                            <th class="py-3 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 font-medium">
                                        <?php if (empty($tech_notes)): ?>
                                            <tr>
                                                <td colspan="4" class="py-8 text-center text-slate-400">No service notes found for this account.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($tech_notes as $tn): 
                                                $st_badge = get_status_badge_class($tn['status']);
                                            ?>
                                                <tr class="hover:bg-slate-50/80 transition-colors">
                                                    <td class="py-3 px-4 space-y-1">
                                                        <div class="flex items-center space-x-2">
                                                            <span class="font-mono text-slate-400">#<?php echo $tn['id']; ?></span>
                                                            <span class="font-bold text-slate-800"><?php echo sanitize($tn['xdate']); ?></span>
                                                        </div>
                                                        <?php if (strpos($tn['reasonoftech'], '[Hardware Pull-Out]') !== false): ?>
                                                            <div>
                                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300">
                                                                    🔄 Hardware Pull-Out
                                                                </span>
                                                                <span class="text-xs text-slate-700 font-semibold ml-1"><?php echo sanitize($tn['reasonoftech']); ?></span>
                                                            </div>
                                                        <?php elseif (!empty($tn['reasonoftech'])): ?>
                                                            <p class="text-xs text-slate-700 truncate max-w-xs"><?php echo sanitize($tn['reasonoftech']); ?></p>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 font-bold text-[#EB3E0B]"><?php echo sanitize($tn['techname']); ?></td>
                                                    <td class="py-3 px-4">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $st_badge; ?>">
                                                            <?php echo sanitize($tn['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-3 px-4 text-right">
                                                        <div class="flex items-center justify-end space-x-1.5">
                                                            <button data-note="<?php echo htmlspecialchars(json_encode($tn), ENT_QUOTES, 'UTF-8'); ?>"
                                                                    onclick="openServiceNoteDetailsModal(this)" 
                                                                    class="bg-slate-100 hover:bg-[#FFE8D5] text-slate-700 hover:text-[#EB3E0B] font-bold text-[11px] px-3 py-1.5 rounded-full inline-flex items-center space-x-1 transition-all">
                                                                <svg class="w-4 h-4 text-[#FA5915]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                                </svg>
                                                                <span>View</span>
                                                            </button>
                                                            <a href="print_document.php?type=technote&id=<?php echo $tn['id']; ?>&autoprint=1" target="_blank" 
                                                               class="p-1.5 rounded-lg bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] inline-flex items-center space-x-1 transition-colors" title="Print / Download Tech Note PDF">
                                                                <svg class="w-3.5 h-3.5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                                </svg>
                                                                <span class="text-[11px] font-bold">Print</span>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <!-- TAB 3: WORK ORDERS -->
                    <?php elseif ($active_tab === 'orders'): ?>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-base font-extrabold text-slate-900">Work Orders & Billing History</h3>
                                    <p class="text-xs text-slate-500">Service statements, supplies, hardware repairs, and billing for Account #<?php echo sanitize($client_acct); ?></p>
                                </div>
                                <?php if ($my_tier >= 2): ?>
                                <button type="button"
                                        data-acct="<?php echo htmlspecialchars($client_acct, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-name="<?php echo htmlspecialchars(!empty($selected_client['tradename']) ? $selected_client['tradename'] : $selected_client['clientname'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-addr="<?php echo htmlspecialchars(!empty($selected_client['address']) ? $selected_client['address'] : '', ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openCreateWorkOrderModalFromBtn(this)" 
                                        class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-4 py-2 rounded-full shadow-sm flex items-center space-x-1.5 transition-all active:scale-95">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    <span>Create Work Order</span>
                                </button>
                                <?php endif; ?>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                            <th class="py-3 px-4">WO # / Date</th>
                                            <th class="py-3 px-4">Nature of Work / Particulars</th>
                                            <?php if ($can_view_spend): ?>
                                                <th class="py-3 px-4">Amount</th>
                                            <?php endif; ?>
                                            <th class="py-3 px-4">OR #</th>
                                            <th class="py-3 px-4">Status</th>
                                            <th class="py-3 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 font-medium">
                                        <?php if (empty($work_orders)): ?>
                                            <tr>
                                                <td colspan="<?php echo $can_view_spend ? 6 : 5; ?>" class="py-8 text-center text-slate-400">No work orders recorded for this account.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($work_orders as $wo): 
                                                $st_badge = get_status_badge_class($wo['status']);
                                            ?>
                                                <tr class="hover:bg-slate-50/80 transition-colors">
                                                    <td class="py-3 px-4 space-y-0.5">
                                                        <div class="font-mono font-bold text-[#EB3E0B]">WO-<?php echo $wo['id']; ?></div>
                                                        <div class="text-slate-500 font-mono text-[11px]"><?php echo format_date($wo['xdate']); ?></div>
                                                    </td>
                                                    <td class="py-3 px-4 max-w-sm font-semibold text-slate-800 leading-relaxed">
                                                        <div><?php echo sanitize($wo['natureofwork']); ?></div>
                                                        <?php if (!empty($wo['address'])): ?>
                                                            <div class="text-[11px] text-slate-500 font-normal flex items-center gap-1 mt-1">
                                                                <svg class="w-3 h-3 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                                <span class="truncate" title="<?php echo sanitize($wo['address']); ?>"><?php echo sanitize($wo['address']); ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php if ($can_view_spend): ?>
                                                        <td class="py-3 px-4 font-mono font-bold text-slate-900 text-sm">₱<?php echo number_format(floatval($wo['amount']), 2); ?></td>
                                                    <?php endif; ?>
                                                    <td class="py-3 px-4 font-mono text-slate-600"><?php echo !empty($wo['ornum']) ? 'OR #' . sanitize($wo['ornum']) : 'N/A'; ?></td>
                                                    <td class="py-3 px-4">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $st_badge; ?>">
                                                            <?php echo sanitize(ucfirst($wo['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-3 px-4 text-right">
                                                        <div class="flex items-center justify-end space-x-1.5">
                                                            <a href="print_document.php?type=workorder&id=<?php echo $wo['id']; ?>&autoprint=1" target="_blank" 
                                                               class="p-1.5 rounded-lg bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] inline-flex items-center space-x-1 transition-colors" title="Print / Download Work Order PDF">
                                                                <svg class="w-3.5 h-3.5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                                </svg>
                                                                <span class="text-[11px] font-bold">Print</span>
                                                            </a>
                                                            <?php if ($my_tier >= 2): ?>
                                                                <button data-wo="<?php echo htmlspecialchars(json_encode($wo), ENT_QUOTES, 'UTF-8'); ?>"
                                                                        onclick="openEditWorkOrderModal(this)" 
                                                                        class="p-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 transition-colors" title="Edit Work Order">
                                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                                    </svg>
                                                                </button>

                                                                <form method="POST" action="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=orders" onsubmit="return confirm('Are you sure you want to delete Work Order #WO-<?php echo $wo['id']; ?>?');" class="inline">
                                                                    <input type="hidden" name="action" value="delete_workorder">
                                                                    <input type="hidden" name="wo_id" value="<?php echo $wo['id']; ?>">
                                                                    <input type="hidden" name="accountnum" value="<?php echo sanitize($client_acct); ?>">
                                                                    <button type="submit" class="p-1.5 rounded-lg bg-slate-100 hover:bg-rose-100 text-slate-500 hover:text-rose-600 transition-colors" title="Delete Work Order">
                                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                                        </svg>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <!-- TAB 4: HARDWARE PULL-OUTS & DEPLOYMENTS -->
                    <?php elseif ($active_tab === 'pullouts'): ?>
                        <div class="space-y-4">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-extrabold text-slate-900">Hardware & Software Pull-Outs & Deployments</h3>
                                    <p class="text-xs text-slate-500">Equipment retrieval, warranty exchange, diagnostics, and stock deployments for Account #<?php echo sanitize($client_acct); ?></p>
                                </div>
                                <?php if ($my_tier >= 2): ?>
                                <a href="inventory.php?pullout_client=<?php echo urlencode($client_acct); ?>" 
                                   class="bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-bold text-xs px-4 py-2.5 rounded-full shadow-sm shadow-amber-500/25 flex items-center space-x-1.5 transition-all active:scale-95 self-start sm:self-auto">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                    </svg>
                                    <span>Record New Pull-Out</span>
                                </a>
                                <?php endif; ?>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                            <th class="py-3 px-4">Date / Time</th>
                                            <th class="py-3 px-4">Item Details</th>
                                            <th class="py-3 px-4 text-center">Movement Type</th>
                                            <th class="py-3 px-4 text-center">Quantity</th>
                                            <th class="py-3 px-4">Technician</th>
                                            <th class="py-3 px-4">Reason & Diagnostic Remarks</th>
                                            <th class="py-3 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 font-medium">
                                        <?php if (empty($client_pullouts)): ?>
                                            <tr>
                                                <td colspan="7" class="py-8 text-center text-slate-400">
                                                    No pull-outs or equipment movements recorded for this account.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($client_pullouts as $po): ?>
                                                <tr class="hover:bg-slate-50/80 transition-colors">
                                                    <td class="py-3 px-4 text-slate-500 whitespace-nowrap font-mono">
                                                        <?php echo format_date($po['created_at']); ?>
                                                    </td>
                                                    <td class="py-3 px-4 font-bold text-slate-900">
                                                        <?php echo sanitize(!empty($po['item_name']) ? $po['item_name'] : 'Item Unit'); ?>
                                                        <?php if (!empty($po['item_code'])): ?>
                                                            <span class="block text-[10px] font-mono text-[#EB3E0B]"><?php echo sanitize($po['item_code']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-center">
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300">
                                                            <?php echo sanitize($po['change_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-3 px-4 text-center font-mono font-bold text-slate-800">
                                                        <?php echo abs(intval($po['quantity_change'])) > 0 ? abs(intval($po['quantity_change'])) : '1'; ?> unit(s)
                                                    </td>
                                                    <td class="py-3 px-4 font-semibold text-slate-800">
                                                        <?php echo sanitize($po['tech_name']); ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-slate-600 max-w-sm">
                                                        <span class="text-xs font-medium text-slate-800 block"><?php echo sanitize($po['notes']); ?></span>
                                                    </td>
                                                    <td class="py-3 px-4 text-right">
                                                        <a href="print_document.php?type=pullout&id=<?php echo $po['id']; ?>&autoprint=1" target="_blank" 
                                                           class="p-1.5 rounded-lg bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] inline-flex items-center space-x-1 transition-colors" title="Print / Download Pull-Out Receipt PDF">
                                                            <svg class="w-3.5 h-3.5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                            </svg>
                                                            <span class="text-[11px] font-bold">Print</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php elseif ($active_tab === 'assets'): ?>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between flex-wrap gap-3">
                                <div>
                                    <h3 class="text-base font-extrabold text-slate-900">Client Software &amp; Hardware</h3>
                                    <p class="text-xs text-slate-500 mt-0.5">Software licences and hardware units on record for this account.</p>
                                </div>
                                <?php if ($my_tier >= 2): ?>
                                    <button onclick="openAssetTypeModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-5 py-2.5 rounded-full shadow-sm transition-all active:scale-95 flex items-center space-x-2 shrink-0">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                        </svg>
                                        <span>Add Item</span>
                                    </button>
                                <?php endif; ?>
                            </div>

                            <?php
                            $assets_total = 0;
                            $count_software = 0;
                            $count_hardware = 0;
                            foreach ($client_assets as $ca) {
                                $assets_total += floatval($ca['total_amount']);
                                if ($ca['asset_type'] === 'Software') { $count_software++; } else { $count_hardware++; }
                            }
                            ?>

                            <div class="grid grid-cols-1 sm:grid-cols-<?php echo $can_view_spend ? '3' : '2'; ?> gap-3">
                                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Software Records</p>
                                    <p class="text-xl font-extrabold text-slate-900 font-mono mt-0.5"><?php echo $count_software; ?></p>
                                </div>
                                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Hardware Records</p>
                                    <p class="text-xl font-extrabold text-slate-900 font-mono mt-0.5"><?php echo $count_hardware; ?></p>
                                </div>
                                <!-- Client spend is commercially sensitive: Super Admin (Master) only -->
                                <?php if ($can_view_spend): ?>
                                    <div class="bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl p-4">
                                        <p class="text-[10px] font-bold text-[#7C2112] uppercase tracking-wider">Total Value</p>
                                        <p class="text-xl font-extrabold text-[#EB3E0B] font-mono mt-0.5">&#8369;<?php echo number_format($assets_total, 2); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                            <th class="py-3 px-4">Type</th>
                                            <th class="py-3 px-4">Item</th>
                                            <th class="py-3 px-4">Serial Number</th>
                                            <th class="py-3 px-4 text-center">Qty</th>
                                            <?php if ($can_view_spend): ?>
                                                <th class="py-3 px-4 text-right">Unit Price</th>
                                                <th class="py-3 px-4 text-right">Total</th>
                                            <?php endif; ?>
                                            <th class="py-3 px-4">Warranty</th>
                                            <th class="py-3 px-4">Recorded</th>
                                            <th class="py-3 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 font-medium">
                                        <?php if (empty($client_assets)): ?>
                                            <tr>
                                                <td colspan="<?php echo $can_view_spend ? 9 : 7; ?>" class="py-8 text-center text-slate-400">
                                                    No software or hardware recorded for this account yet.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($client_assets as $ca): ?>
                                                <tr class="hover:bg-slate-50/80 transition-colors">
                                                    <td class="py-3 px-4">
                                                        <?php if ($ca['asset_type'] === 'Software'): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-blue-50 text-blue-700 border border-blue-200">Software</span>
                                                        <?php else: ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-50 text-amber-800 border border-amber-200">Hardware</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4">
                                                        <span class="block font-bold text-slate-900"><?php echo sanitize($ca['name']); ?></span>
                                                        <?php if (!empty($ca['item_code'])): ?>
                                                            <span class="text-[11px] text-slate-500 font-mono"><?php echo sanitize($ca['item_code']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($ca['notes'])): ?>
                                                            <span class="block text-[11px] text-slate-500 mt-0.5"><?php echo sanitize($ca['notes']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 font-mono text-slate-600">
                                                        <?php echo !empty($ca['serial_number']) ? sanitize($ca['serial_number']) : '<span class="text-slate-300">&mdash;</span>'; ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-center font-mono font-bold text-slate-800"><?php echo intval($ca['quantity']); ?></td>
                                                    <?php if ($can_view_spend): ?>
                                                        <td class="py-3 px-4 text-right font-mono text-slate-600">&#8369;<?php echo number_format($ca['unit_price'], 2); ?></td>
                                                        <td class="py-3 px-4 text-right font-mono font-bold text-slate-900">&#8369;<?php echo number_format($ca['total_amount'], 2); ?></td>
                                                    <?php endif; ?>
                                                    <td class="py-3 px-4">
                                                        <?php
                                                        $ca_today   = strtotime(date('Y-m-d'));
                                                        $ca_expired = (!empty($ca['warranty_expiry']) && strtotime($ca['warranty_expiry']) < $ca_today);
                                                        $ca_pending = (!empty($ca['warranty_start']) && strtotime($ca['warranty_start']) > $ca_today);
                                                        ?>
                                                        <?php if ($ca['warranty_status'] === 'Active' && $ca_expired): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200">Expired</span>
                                                        <?php elseif ($ca['warranty_status'] === 'Active' && $ca_pending): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-600 border border-slate-300">Not Yet Started</span>
                                                        <?php elseif ($ca['warranty_status'] === 'Active'): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">Under Warranty</span>
                                                        <?php else: ?>
                                                            <span class="text-[11px] text-slate-400">No warranty</span>
                                                        <?php endif; ?>

                                                        <?php if ($ca['warranty_status'] === 'Active' && (!empty($ca['warranty_start']) || !empty($ca['warranty_expiry']))): ?>
                                                            <span class="block text-[11px] text-slate-500 font-mono mt-0.5">
                                                                <?php echo !empty($ca['warranty_start']) ? format_date_only($ca['warranty_start']) : '&mdash;'; ?>
                                                                &rarr;
                                                                <?php echo !empty($ca['warranty_expiry']) ? format_date_only($ca['warranty_expiry']) : '&mdash;'; ?>
                                                            </span>
                                                        <?php endif; ?>

                                                        <?php if (!empty($ca['warranty_notes'])): ?>
                                                            <span class="block text-[11px] text-slate-500 mt-0.5"><?php echo sanitize($ca['warranty_notes']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-slate-500 whitespace-nowrap">
                                                        <span class="block"><?php echo format_date($ca['created_at']); ?></span>
                                                        <?php if (!empty($ca['recorded_by'])): ?>
                                                            <span class="text-[11px] text-slate-400">by <?php echo sanitize($ca['recorded_by']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-right whitespace-nowrap">
                                                        <div class="flex items-center justify-end space-x-1.5">
                                                            <a href="print_document.php?type=asset&id=<?php echo $ca['id']; ?>&autoprint=1" target="_blank" 
                                                               class="px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] border border-slate-200 font-bold text-[11px] inline-flex items-center space-x-1 transition-colors" title="Print / Download Asset Certificate PDF">
                                                                <svg class="w-3 h-3 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                                </svg>
                                                                <span>Print</span>
                                                            </a>
                                                            <?php if ($my_tier >= 2): ?>
                                                                <button type="button"
                                                                        onclick='openEditAssetModal(<?php echo htmlspecialchars(json_encode($ca), ENT_QUOTES, "UTF-8"); ?>)'
                                                                        class="px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-slate-800 text-slate-600 hover:text-white border border-slate-200 font-bold text-[11px] transition-colors"
                                                                        title="Edit this item">
                                                                    Edit
                                                                </button>
                                                                <button type="button"
                                                                        onclick='openDeleteAssetModal(<?php echo intval($ca["id"]); ?>, <?php echo htmlspecialchars(json_encode($ca["name"]), ENT_QUOTES, "UTF-8"); ?>)'
                                                                        class="px-2.5 py-1 rounded-xl bg-rose-50 hover:bg-rose-600 text-rose-700 hover:text-white border border-rose-200 font-bold text-[11px] transition-colors"
                                                                        title="Delete this item">
                                                                    Delete
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="text-[11px] text-slate-300">View only</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>


                <!-- CHOOSE ITEM TYPE MODAL -->
                <div id="assetTypeModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn">
                        <button onclick="closeAssetTypeModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="mb-6">
                            <h3 class="text-lg font-extrabold text-slate-900">Add Item to Client</h3>
                            <p class="text-xs text-slate-500 mt-0.5">What are you recording for <strong><?php echo sanitize($selected_client['tradename']); ?></strong>?</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <button onclick="chooseAssetType('software')" class="group p-5 rounded-2xl border-2 border-slate-200 hover:border-blue-500 hover:bg-blue-50 transition-all text-center space-y-2">
                                <div class="w-12 h-12 rounded-2xl bg-blue-50 group-hover:bg-blue-500 text-blue-600 group-hover:text-white flex items-center justify-center mx-auto transition-colors">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                    </svg>
                                </div>
                                <span class="block text-sm font-extrabold text-slate-900">Software</span>
                                <span class="block text-[11px] text-slate-500">Name and price</span>
                            </button>

                            <button onclick="chooseAssetType('hardware')" class="group p-5 rounded-2xl border-2 border-slate-200 hover:border-amber-500 hover:bg-amber-50 transition-all text-center space-y-2">
                                <div class="w-12 h-12 rounded-2xl bg-amber-50 group-hover:bg-amber-500 text-amber-600 group-hover:text-white flex items-center justify-center mx-auto transition-colors">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                                <span class="block text-sm font-extrabold text-slate-900">Hardware</span>
                                <span class="block text-[11px] text-slate-500">From inventory list</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ADD SOFTWARE MODAL -->
                <div id="addSoftwareModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto">
                        <button onclick="closeAddSoftwareModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 mb-6">
                            <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shadow-sm">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">Add Software</h3>
                                <p class="text-xs text-slate-500">Recording for Account #<?php echo sanitize($client_acct); ?></p>
                            </div>
                        </div>

                        <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=assets" method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="add_client_asset">
                            <input type="hidden" name="asset_type" value="Software">
                            <input type="hidden" name="accountnum" value="<?php echo sanitize($client_acct); ?>">

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Software Name *</label>
                                <input type="text" name="software_name" required placeholder="e.g. RNZ POS System - 1 Terminal Licence" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-blue-500 focus:outline-none transition-all">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Software Price</label>
                                <input type="number" step="0.01" min="0" name="unit_price" value="0.00" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-blue-500 focus:outline-none transition-all">
                                <p class="text-[10px] text-slate-500 mt-1">Saving raises an <strong class="text-amber-700">Unpaid</strong> work order for this client at this price &mdash; mark it Paid in the Work Orders tab once settled.</p>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Notes</label>
                                <textarea name="notes" rows="2" placeholder="Optional remarks" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-blue-500 focus:outline-none transition-all"></textarea>
                            </div>

                            <div class="pt-4 border-t border-slate-100 space-y-4">
                                <div class="flex items-center space-x-2">
                                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    <p class="text-xs font-extrabold text-slate-700 uppercase tracking-wider">Item Warranty</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Status</label>
                                    <select name="warranty_status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                        <option value="Inactive" selected>No Warranty</option>
                                        <option value="Active">Under Warranty</option>
                                    </select>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Start</label>
                                        <input type="date" name="warranty_start" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Expiry</label>
                                        <input type="date" name="warranty_expiry" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Notes</label>
                                    <textarea name="warranty_notes" rows="2" placeholder="e.g. 1 year parts and service" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all"></textarea>
                                </div>
                            </div>

                            <?php if ($my_tier === 2): ?>
                                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                                    <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        <span>Security Access Code Required (Level 2 Account)</span>
                                    </label>
                                    <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                                </div>
                            <?php endif; ?>

                            <div class="pt-4 flex items-center justify-end space-x-3 border-t border-slate-100">
                                <button type="button" onclick="closeAddSoftwareModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">Cancel</button>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">Save Software</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ADD HARDWARE MODAL -->
                <div id="addHardwareModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto">
                        <button onclick="closeAddHardwareModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 mb-6">
                            <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shadow-sm">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">Add Hardware</h3>
                                <p class="text-xs text-slate-500">Recording for Account #<?php echo sanitize($client_acct); ?></p>
                            </div>
                        </div>

                        <?php if (empty($inventory_items)): ?>
                            <div class="p-4 bg-amber-50 border border-amber-200 rounded-2xl text-xs font-bold text-amber-900">
                                No active inventory items found. Add hardware items in <a href="inventory.php" class="underline">Inventory</a> first.
                            </div>
                            <div class="pt-4 flex items-center justify-end">
                                <button type="button" onclick="closeAddHardwareModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">Close</button>
                            </div>
                        <?php else: ?>
                            <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=assets" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="add_client_asset">
                                <input type="hidden" name="asset_type" value="Hardware">
                                <input type="hidden" name="accountnum" value="<?php echo sanitize($client_acct); ?>">

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Hardware Type *</label>
                                    <select name="item_id" id="hardware_item_select" required onchange="onHardwareItemChange()" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-amber-500 focus:outline-none transition-all">
                                        <option value="">-- Select hardware from inventory --</option>
                                        <?php foreach ($inventory_items as $inv): ?>
                                            <?php $inv_price = ($inv['selling_price'] > 0) ? $inv['selling_price'] : $inv['unit_price']; ?>
                                            <option value="<?php echo intval($inv['id']); ?>" data-price="<?php echo sanitize($inv_price); ?>">
                                                <?php echo sanitize($inv['name']); ?> (<?php echo sanitize($inv['item_code']); ?>) &mdash; <?php echo intval($inv['quantity']); ?> in stock
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-[10px] text-slate-500 mt-1">Saving deducts the quantity from Good / Functional stock and raises an <strong class="text-amber-700">Unpaid</strong> work order for this client &mdash; mark it Paid in the Work Orders tab once settled.</p>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Quantity *</label>
                                        <input type="number" min="1" step="1" name="quantity" id="hardware_qty" value="1" required oninput="updateHardwareTotal()" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-amber-500 focus:outline-none transition-all">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Unit Price</label>
                                        <input type="number" step="0.01" min="0" name="unit_price" id="hardware_price" value="0.00" oninput="updateHardwareTotal()" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-amber-500 focus:outline-none transition-all">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Serial Number</label>
                                    <input type="text" name="serial_number" placeholder="e.g. SN-4471-KD" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-amber-500 focus:outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Notes</label>
                                    <textarea name="notes" rows="2" placeholder="Optional remarks" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-amber-500 focus:outline-none transition-all"></textarea>
                                </div>

                                <div class="pt-4 border-t border-slate-100 space-y-4">
                                    <div class="flex items-center space-x-2">
                                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                        <p class="text-xs font-extrabold text-slate-700 uppercase tracking-wider">Item Warranty</p>
                                    </div>
    
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Status</label>
                                        <select name="warranty_status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                            <option value="Inactive" selected>No Warranty</option>
                                            <option value="Active">Under Warranty</option>
                                        </select>
                                    </div>
    
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Start</label>
                                            <input type="date" name="warranty_start" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                        </div>
    
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Expiry</label>
                                            <input type="date" name="warranty_expiry" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                        </div>
                                    </div>
    
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Notes</label>
                                        <textarea name="warranty_notes" rows="2" placeholder="e.g. 1 year parts and service" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all"></textarea>
                                    </div>
                                </div>
    
                                <div class="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between">
                                    <span class="text-xs font-bold text-slate-600 uppercase tracking-wider">Total</span>
                                    <span id="hardware_total" class="text-lg font-extrabold text-[#EB3E0B] font-mono">&#8369;0.00</span>
                                </div>

                                <?php if ($my_tier === 2): ?>
                                    <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                                        <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                                            <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                            <span>Security Access Code Required (Level 2 Account)</span>
                                        </label>
                                        <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                                    </div>
                                <?php endif; ?>

                                <div class="pt-4 flex items-center justify-end space-x-3 border-t border-slate-100">
                                    <button type="button" onclick="closeAddHardwareModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">Cancel</button>
                                    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">Save Hardware</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                function openAssetTypeModal() {
                    var m = document.getElementById('assetTypeModal');
                    if (m) m.classList.remove('hidden');
                }
                function closeAssetTypeModal() {
                    var m = document.getElementById('assetTypeModal');
                    if (m) m.classList.add('hidden');
                }
                function chooseAssetType(kind) {
                    closeAssetTypeModal();
                    var m = document.getElementById(kind === 'software' ? 'addSoftwareModal' : 'addHardwareModal');
                    if (m) m.classList.remove('hidden');
                }
                function closeAddSoftwareModal() {
                    var m = document.getElementById('addSoftwareModal');
                    if (m) m.classList.add('hidden');
                }
                function closeAddHardwareModal() {
                    var m = document.getElementById('addHardwareModal');
                    if (m) m.classList.add('hidden');
                }
                function onHardwareItemChange() {
                    var sel = document.getElementById('hardware_item_select');
                    var priceField = document.getElementById('hardware_price');
                    if (!sel || !priceField) return;
                    var opt = sel.options[sel.selectedIndex];
                    var price = opt ? opt.getAttribute('data-price') : null;
                    // Prefill from the inventory selling price, but leave it editable
                    if (price !== null && price !== '') {
                        priceField.value = parseFloat(price).toFixed(2);
                    }
                    updateHardwareTotal();
                }
                function updateHardwareTotal() {
                    var qty = parseInt(document.getElementById('hardware_qty').value, 10);
                    var price = parseFloat(document.getElementById('hardware_price').value);
                    if (isNaN(qty) || qty < 1) qty = 0;
                    if (isNaN(price) || price < 0) price = 0;
                    var out = document.getElementById('hardware_total');
                    if (out) out.textContent = '₱' + (qty * price).toFixed(2);
                }
                </script>

                <!-- EDIT CLIENT ASSET MODAL -->
                <div id="editAssetModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto">
                        <button onclick="closeEditAssetModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 mb-6">
                            <div class="w-10 h-10 rounded-2xl bg-slate-100 text-slate-700 flex items-center justify-center shadow-sm">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">Edit Item</h3>
                                <p class="text-xs text-slate-500">Editing <span id="edit_asset_kind" class="font-bold">item</span> on Account #<?php echo sanitize($client_acct); ?></p>
                            </div>
                        </div>

                        <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=assets" method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="update_client_asset">
                            <input type="hidden" name="accountnum" value="<?php echo sanitize($client_acct); ?>">
                            <input type="hidden" name="asset_id" id="edit_asset_id" value="">

                            <!-- Software-only field -->
                            <div id="edit_software_fields" class="hidden">
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Software Name *</label>
                                <input type="text" name="software_name" id="edit_software_name" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-blue-500 focus:outline-none transition-all">
                            </div>

                            <!-- Hardware-only fields -->
                            <div id="edit_hardware_fields" class="hidden space-y-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Hardware Type *</label>
                                    <select name="item_id" id="edit_item_id" onchange="onEditHardwareItemChange()" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-amber-500 focus:outline-none transition-all">
                                        <option value="">-- Select hardware from inventory --</option>
                                        <?php foreach ($inventory_items as $inv): ?>
                                            <?php $inv_price = ($inv['selling_price'] > 0) ? $inv['selling_price'] : $inv['unit_price']; ?>
                                            <option value="<?php echo intval($inv['id']); ?>" data-price="<?php echo sanitize($inv_price); ?>">
                                                <?php echo sanitize($inv['name']); ?> (<?php echo sanitize($inv['item_code']); ?>) &mdash; <?php echo intval($inv['quantity']); ?> in stock
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Serial Number</label>
                                    <input type="text" name="serial_number" id="edit_serial_number" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-amber-500 focus:outline-none transition-all">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div id="edit_qty_wrap">
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Quantity *</label>
                                    <input type="number" min="1" step="1" name="quantity" id="edit_quantity" value="1" oninput="updateEditTotal()" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Unit Price</label>
                                    <input type="number" step="0.01" min="0" name="unit_price" id="edit_unit_price" value="0.00" oninput="updateEditTotal()" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Notes</label>
                                <textarea name="notes" id="edit_notes" rows="2" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
                            </div>

                            <div class="pt-4 border-t border-slate-100 space-y-4">
                                <div class="flex items-center space-x-2">
                                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    <p class="text-xs font-extrabold text-slate-700 uppercase tracking-wider">Item Warranty</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Status</label>
                                    <select name="warranty_status" id="edit_warranty_status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                        <option value="Inactive">No Warranty</option>
                                        <option value="Active">Under Warranty</option>
                                    </select>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Start</label>
                                        <input type="date" name="warranty_start" id="edit_warranty_start" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Expiry</label>
                                        <input type="date" name="warranty_expiry" id="edit_warranty_expiry" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Notes</label>
                                    <textarea name="warranty_notes" id="edit_warranty_notes" rows="2" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all"></textarea>
                                </div>
                            </div>

                            <div class="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-600 uppercase tracking-wider">Total</span>
                                <span id="edit_total" class="text-lg font-extrabold text-[#EB3E0B] font-mono">&#8369;0.00</span>
                            </div>

                            <?php if ($my_tier === 2): ?>
                                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                                    <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        <span>Security Access Code Required (Level 2 Account)</span>
                                    </label>
                                    <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                                </div>
                            <?php endif; ?>

                            <div class="pt-4 flex items-center justify-end space-x-3 border-t border-slate-100">
                                <button type="button" onclick="closeEditAssetModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">Cancel</button>
                                <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- DELETE CLIENT ASSET CONFIRM MODAL -->
                <div id="deleteAssetModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center shadow-sm">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">Delete Item</h3>
                                <p class="text-xs text-slate-500">This cannot be undone.</p>
                            </div>
                        </div>

                        <p class="text-xs text-slate-600 mb-6">
                            Remove <strong id="delete_asset_name" class="text-slate-900"></strong> from this client's records?
                        </p>

                        <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=assets" method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="delete_client_asset">
                            <input type="hidden" name="accountnum" value="<?php echo sanitize($client_acct); ?>">
                            <input type="hidden" name="asset_id" id="delete_asset_id" value="">

                            <?php if ($my_tier === 2): ?>
                                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                                    <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        <span>Security Access Code Required (Level 2 Account)</span>
                                    </label>
                                    <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                                </div>
                            <?php endif; ?>

                            <div class="flex items-center justify-end space-x-3">
                                <button type="button" onclick="closeDeleteAssetModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">Cancel</button>
                                <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">Delete Item</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                function openEditAssetModal(asset) {
                    var isSoftware = (asset.asset_type === 'Software');

                    document.getElementById('edit_asset_id').value = asset.id;
                    document.getElementById('edit_asset_kind').textContent = isSoftware ? 'software' : 'hardware';
                    document.getElementById('edit_unit_price').value = parseFloat(asset.unit_price || 0).toFixed(2);
                    document.getElementById('edit_quantity').value = parseInt(asset.quantity, 10) || 1;
                    document.getElementById('edit_notes').value = asset.notes || '';
                    document.getElementById('edit_warranty_status').value = (asset.warranty_status === 'Active') ? 'Active' : 'Inactive';
                    document.getElementById('edit_warranty_start').value = asset.warranty_start || '';
                    document.getElementById('edit_warranty_expiry').value = asset.warranty_expiry || '';
                    document.getElementById('edit_warranty_notes').value = asset.warranty_notes || '';

                    var swFields = document.getElementById('edit_software_fields');
                    var hwFields = document.getElementById('edit_hardware_fields');
                    var qtyWrap  = document.getElementById('edit_qty_wrap');
                    var itemSel  = document.getElementById('edit_item_id');
                    var swName   = document.getElementById('edit_software_name');

                    if (isSoftware) {
                        swFields.classList.remove('hidden');
                        hwFields.classList.add('hidden');
                        qtyWrap.classList.add('hidden');
                        swName.value = asset.name || '';
                        // Disabled inputs are not submitted, so the handler keeps its own defaults
                        itemSel.disabled = true;
                        swName.disabled = false;
                    } else {
                        swFields.classList.add('hidden');
                        hwFields.classList.remove('hidden');
                        qtyWrap.classList.remove('hidden');
                        document.getElementById('edit_serial_number').value = asset.serial_number || '';
                        itemSel.disabled = false;
                        itemSel.value = asset.item_id || '';
                        swName.disabled = true;
                    }

                    updateEditTotal();
                    document.getElementById('editAssetModal').classList.remove('hidden');
                }
                function closeEditAssetModal() {
                    document.getElementById('editAssetModal').classList.add('hidden');
                }
                function onEditHardwareItemChange() {
                    var sel = document.getElementById('edit_item_id');
                    var opt = sel.options[sel.selectedIndex];
                    var price = opt ? opt.getAttribute('data-price') : null;
                    if (price !== null && price !== '' && parseFloat(price) > 0) {
                        document.getElementById('edit_unit_price').value = parseFloat(price).toFixed(2);
                    }
                    updateEditTotal();
                }
                function updateEditTotal() {
                    var qty = parseInt(document.getElementById('edit_quantity').value, 10);
                    var price = parseFloat(document.getElementById('edit_unit_price').value);
                    if (isNaN(qty) || qty < 1) qty = 1;
                    if (isNaN(price) || price < 0) price = 0;
                    document.getElementById('edit_total').textContent = '₱' + (qty * price).toFixed(2);
                }
                function openDeleteAssetModal(id, name) {
                    document.getElementById('delete_asset_id').value = id;
                    document.getElementById('delete_asset_name').textContent = name;
                    document.getElementById('deleteAssetModal').classList.remove('hidden');
                }
                function closeDeleteAssetModal() {
                    document.getElementById('deleteAssetModal').classList.add('hidden');
                }
                </script>
                <!-- EDIT ACCOUNT DETAILS MODAL -->
                <div id="editAccountModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto">
                        <!-- Close Button -->
                        <button onclick="closeEditAccountModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 mb-6">
                            <div class="w-10 h-10 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold shadow-sm">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">Edit Client Account Details</h3>
                                <p class="text-xs text-slate-500">Update client profile information in bucket_client table.</p>
                            </div>
                        </div>

                        <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=<?php echo $active_tab; ?>" method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="update_client_profile">
                            <input type="hidden" name="accountnum" value="<?php echo sanitize($selected_client['accountnum']); ?>">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Trade Business Name *</label>
                                    <input type="text" name="tradename" value="<?php echo sanitize($selected_client['tradename']); ?>" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Owner / Client Name *</label>
                                    <input type="text" name="clientname" value="<?php echo sanitize($selected_client['clientname']); ?>" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Contact Number</label>
                                    <input type="text" name="contactnum" value="<?php echo sanitize($selected_client['contactnum']); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Email Address</label>
                                    <input type="email" name="emailaddress" value="<?php echo sanitize($selected_client['emailaddress']); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Physical Address</label>
                                <textarea name="address" rows="2" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"><?php echo sanitize($selected_client['address']); ?></textarea>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Client Type</label>
                                    <input type="text" name="type" value="<?php echo sanitize($selected_client['type']); ?>" placeholder="POS Client / Standard" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Monthly Retainer Fee</label>
                                    <input type="text" name="monthlyretainersfee" value="<?php echo sanitize($selected_client['monthlyretainersfee']); ?>" placeholder="e.g. 5000" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>
                            </div>

                            <!-- Access Level Tier Banner & Input -->
                            <?php if ($my_tier === 1): ?>
                                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot modify client profiles.</span>
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

                            <div class="pt-4 flex items-center justify-end space-x-3 border-t border-slate-100">
                                <button type="button" onclick="closeEditAccountModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                                    Cancel
                                </button>
                                <?php if ($my_tier === 1): ?>
                                    <button type="button" disabled class="bg-slate-300 text-slate-500 font-bold text-xs px-6 py-2.5 rounded-full cursor-not-allowed">
                                        🔒 View Only
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">
                                        Save Account Profile
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- SET / EDIT WARRANTY MODAL -->
                <div id="warrantyModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn space-y-6">
                        <button onclick="closeWarrantyModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-800 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 border-b border-slate-100 pb-4">
                            <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold shadow-sm shrink-0">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">Manage Account Warranty Protection</h3>
                                <p class="text-xs text-slate-500 font-mono">Account #<?php echo sanitize($selected_client['accountnum']); ?> - <?php echo sanitize($selected_client['tradename']); ?></p>
                            </div>
                        </div>

                        <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>" method="POST" class="space-y-4 text-xs">
                            <input type="hidden" name="action" value="update_client_warranty">
                            <input type="hidden" name="accountnum" value="<?php echo sanitize($selected_client['accountnum']); ?>">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1.5">Warranty Status</label>
                                    <select name="warranty_status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all font-bold">
                                        <option value="Active" <?php echo ($has_warranty) ? 'selected' : ''; ?>>Active Warranty</option>
                                        <option value="Inactive" <?php echo (!$has_warranty) ? 'selected' : ''; ?>>Inactive / No Warranty</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1.5">Coverage Scope</label>
                                    <?php $cur_cov = isset($selected_client['warranty_coverage_type']) ? $selected_client['warranty_coverage_type'] : 'Both'; ?>
                                    <select name="warranty_coverage_type" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all font-bold">
                                        <option value="Both" <?php echo ($cur_cov === 'Both') ? 'selected' : ''; ?>>Software & Hardware</option>
                                        <option value="Software" <?php echo ($cur_cov === 'Software') ? 'selected' : ''; ?>>Software Only</option>
                                        <option value="Hardware" <?php echo ($cur_cov === 'Hardware') ? 'selected' : ''; ?>>Hardware Only</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1.5">Warranty Expiry Date (Optional)</label>
                                <input type="date" 
                                       id="warrantyExpiryInput"
                                       name="warranty_expiry" 
                                       value="<?php echo !empty($selected_client['warranty_expiry']) ? sanitize($selected_client['warranty_expiry']) : ''; ?>"
                                       class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all font-mono font-bold">
                                
                                <div class="mt-2 flex items-center space-x-2 text-[11px]">
                                    <span class="text-slate-400 font-bold">Quick Presets:</span>
                                    <button type="button" onclick="setWarrantyPresetMonths(6)" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-2.5 py-1 rounded-lg transition-colors">+6 Months</button>
                                    <button type="button" onclick="setWarrantyPresetMonths(12)" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-2.5 py-1 rounded-lg transition-colors">+1 Year</button>
                                    <button type="button" onclick="document.getElementById('warrantyExpiryInput').value=''" class="bg-slate-100 hover:bg-slate-200 text-slate-500 font-bold px-2.5 py-1 rounded-lg transition-colors">Clear Date</button>
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1.5">Warranty Coverage & Details</label>
                                <textarea name="warranty_notes" rows="3" placeholder="e.g., Full POS Hardware, Thermal Printer, and Maintenance Coverage" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-emerald-500 focus:outline-none transition-all font-medium"><?php echo !empty($selected_client['warranty_notes']) ? sanitize($selected_client['warranty_notes']) : ''; ?></textarea>
                            </div>

                            <!-- Access Level Tier Banner & Input -->
                            <?php if ($my_tier === 1): ?>
                                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot modify warranties.</span>
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

                            <div class="pt-2 flex justify-end space-x-3">
                                <button type="button" onclick="closeWarrantyModal()" class="px-5 py-2.5 rounded-xl font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                                    Cancel
                                </button>
                                <?php if ($my_tier === 1): ?>
                                    <button type="button" disabled class="bg-slate-300 text-slate-500 font-bold text-xs px-6 py-2.5 rounded-full cursor-not-allowed">
                                        🔒 View Only
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">
                                        Save Warranty Settings
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                        </form>
                    </div>
                </div>

                <script>
                function openWarrantyModal() {
                    document.getElementById('warrantyModal').classList.remove('hidden');
                }
                function closeWarrantyModal() {
                    document.getElementById('warrantyModal').classList.add('hidden');
                }
                function setWarrantyPresetMonths(m) {
                    var d = new Date();
                    d.setMonth(d.getMonth() + m);
                    var yr = d.getFullYear();
                    var mo = String(d.getMonth() + 1);
                    if (mo.length === 1) mo = '0' + mo;
                    var day = String(d.getDate());
                    if (day.length === 1) day = '0' + day;
                    document.getElementById('warrantyExpiryInput').value = yr + '-' + mo + '-' + day;
                }
                </script>

                <!-- VIEW SERVICE NOTE DETAILS MODAL -->
                <div id="viewServiceNoteModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto space-y-6">
                        <!-- Close Button -->
                        <button onclick="closeServiceNoteModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 border-b border-slate-100 pb-4">
                            <div class="w-12 h-12 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold shadow-sm shrink-0">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <div class="flex items-center space-x-2">
                                    <span id="v_note_id" class="text-xs font-mono font-bold text-[#EB3E0B]">#000</span>
                                    <span id="v_note_date" class="text-xs text-slate-500 font-mono">Date</span>
                                </div>
                                <h3 class="text-lg font-extrabold text-slate-900">Technical Service Note Details</h3>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 text-xs">
                            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/80">
                                <span class="block text-slate-400 font-bold uppercase text-[10px]">Technician</span>
                                <p id="v_note_tech" class="font-extrabold text-[#EB3E0B] text-sm mt-0.5">-</p>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/80">
                                <span class="block text-slate-400 font-bold uppercase text-[10px]">Status</span>
                                <div id="v_note_status" class="font-bold text-slate-800 text-sm mt-0.5">-</div>
                            </div>
                        </div>

                        <div class="space-y-4 text-xs">
                            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-1">
                                <span class="block text-[#EB3E0B] font-bold uppercase text-[10px] tracking-wider">Reason for Visit / Requested Service</span>
                                <p id="v_note_reason" class="font-semibold text-slate-900 leading-relaxed whitespace-pre-wrap text-xs sm:text-sm">-</p>
                            </div>

                            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-1">
                                <span class="block text-amber-700 font-bold uppercase text-[10px] tracking-wider">Cause of the Issue</span>
                                <p id="v_note_cause" class="font-medium text-slate-800 leading-relaxed whitespace-pre-wrap text-xs sm:text-sm">-</p>
                            </div>

                            <div class="p-4 rounded-2xl bg-emerald-50/70 border border-emerald-200 space-y-1">
                                <span class="block text-emerald-700 font-bold uppercase text-[10px] tracking-wider">Resolution / Solution Work Done</span>
                                <p id="v_note_resso" class="font-semibold text-emerald-950 leading-relaxed whitespace-pre-wrap text-xs sm:text-sm">-</p>
                            </div>
                        </div>

                        <div class="pt-3 flex items-center justify-between border-t border-slate-100">
                            <a id="v_note_print_btn" href="#" target="_blank" 
                               class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-5 py-2.5 rounded-full shadow-md flex items-center space-x-1.5 transition-all">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                </svg>
                                <span>Print / Download PDF</span>
                            </a>
                            <button onclick="closeServiceNoteModal()" class="bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-sm">
                                Close Details
                            </button>
                        </div>
                    </div>
                </div>

                <!-- CREATE WORK ORDER MODAL -->
                <div id="createWorkOrderModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto space-y-6">
                        <!-- Close Button -->
                        <button onclick="closeCreateWorkOrderModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 border-b border-slate-100 pb-4">
                            <div class="w-12 h-12 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold shadow-sm shrink-0">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">Create New Work Order</h3>
                                <p class="text-xs text-slate-500">Record billing statement or service repair for Account #<?php echo sanitize($client_acct); ?></p>
                            </div>
                        </div>

                        <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=orders" method="POST" class="space-y-4 text-xs">
                            <input type="hidden" name="action" value="create_workorder">
                            <input type="hidden" name="accountnum" id="create_wo_accountnum" value="<?php echo sanitize($client_acct); ?>">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Client Name / Trade Name</label>
                                    <input type="text" name="clientname" id="create_wo_clientname" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-semibold">
                                </div>

                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Work Order Date</label>
                                    <input type="date" name="xdate" id="create_wo_xdate" value="<?php echo date('Y-m-d'); ?>" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-mono font-bold">
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Client Address</label>
                                <input type="text" name="address" id="create_wo_address" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider">Nature of Work / Particulars <span class="text-[#EB3E0B]">*</span></label>
                                    <span class="text-[10px] text-slate-400 font-medium">Service description, parts or supplies</span>
                                </div>
                                <textarea name="natureofwork" id="create_wo_natureofwork" rows="3" required placeholder="e.g., POS Maintenance and Database Backup, Thermal Printer repair..." class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all leading-relaxed"></textarea>
                                
                                <div class="mt-1.5 flex flex-wrap gap-1.5 text-[10px]">
                                    <span class="text-slate-400 font-bold self-center mr-1">Quick Presets:</span>
                                    <button type="button" onclick="appendWoNature('POS Maintenance and Database Backup')" class="bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] font-bold px-2 py-0.5 rounded-md transition-colors">+ POS Maintenance</button>
                                    <button type="button" onclick="appendWoNature('10pcs 80mm Thermal Paper Roll Supply')" class="bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] font-bold px-2 py-0.5 rounded-md transition-colors">+ Thermal Paper Supply</button>
                                    <button type="button" onclick="appendWoNature('Thermal Receipt Printer Hardware Troubleshooting & Repair')" class="bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] font-bold px-2 py-0.5 rounded-md transition-colors">+ Printer Repair</button>
                                    <button type="button" onclick="appendWoNature('System Unit Diagnostic & Troubleshooting')" class="bg-slate-100 hover:bg-[#FFE8D5] text-slate-600 hover:text-[#EB3E0B] font-bold px-2 py-0.5 rounded-md transition-colors">+ System Unit Diagnostic</button>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Amount (₱)</label>
                                    <input type="number" step="0.01" min="0" name="amount" id="create_wo_amount" value="0.00" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-mono font-bold">
                                </div>

                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">O.R. Number (Optional)</label>
                                    <input type="text" name="ornum" id="create_wo_ornum" placeholder="e.g. 01795" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-mono">
                                </div>

                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Payment Status</label>
                                    <select name="status" id="create_wo_status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-bold">
                                        <option value="paid">Paid</option>
                                        <option value="unpaid">Unpaid</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Access Level Tier Banner & Input -->
                            <?php if ($my_tier === 1): ?>
                                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot create work orders.</span>
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

                            <div class="pt-3 flex items-center justify-end space-x-3 border-t border-slate-100">
                                <button type="button" onclick="closeCreateWorkOrderModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                                    Cancel
                                </button>
                                <?php if ($my_tier === 1): ?>
                                    <button type="button" disabled class="bg-slate-300 text-slate-500 font-bold text-xs px-6 py-2.5 rounded-full cursor-not-allowed">
                                        🔒 View Only
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md shadow-[#EB3E0B]/30 transition-all active:scale-95">
                                        Save Work Order
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- EDIT WORK ORDER MODAL -->
                <div id="editWorkOrderModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto space-y-6">
                        <!-- Close Button -->
                        <button onclick="closeEditWorkOrderModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 border-b border-slate-100 pb-4">
                            <div class="w-12 h-12 rounded-2xl bg-slate-900 text-white flex items-center justify-center font-bold shadow-sm shrink-0">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </div>
                            <div>
                                <span id="edit_wo_title_id" class="text-xs font-mono font-bold text-[#EB3E0B]">#WO-000</span>
                                <h3 class="text-lg font-extrabold text-slate-900">Edit Work Order Statement</h3>
                            </div>
                        </div>

                        <form action="accounts.php?q=<?php echo urlencode($client_acct); ?>&tab=orders" method="POST" class="space-y-4 text-xs">
                            <input type="hidden" name="action" value="update_workorder">
                            <input type="hidden" name="wo_id" id="edit_wo_id" value="0">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Client Name / Trade Name</label>
                                    <input type="text" name="clientname" id="edit_wo_clientname" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-semibold">
                                </div>

                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Work Order Date</label>
                                    <input type="date" name="xdate" id="edit_wo_xdate" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-mono font-bold">
                                </div>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Client Address / Service Location</label>
                                <input type="text" name="address" id="edit_wo_address" placeholder="Enter service address or location" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Nature of Work / Particulars <span class="text-[#EB3E0B]">*</span></label>
                                <textarea name="natureofwork" id="edit_wo_natureofwork" rows="3" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all leading-relaxed"></textarea>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Amount (₱)</label>
                                    <input type="number" step="0.01" min="0" name="amount" id="edit_wo_amount" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-mono font-bold">
                                </div>

                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">O.R. Number (Optional)</label>
                                    <input type="text" name="ornum" id="edit_wo_ornum" placeholder="e.g. 01795" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-mono">
                                </div>

                                <div>
                                    <label class="block font-bold text-slate-700 uppercase tracking-wider mb-1">Payment Status</label>
                                    <select name="status" id="edit_wo_status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-bold">
                                        <option value="paid">Paid</option>
                                        <option value="unpaid">Unpaid</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Access Level Tier Banner & Input -->
                            <?php if ($my_tier === 1): ?>
                                <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl text-xs font-bold flex items-center space-x-2">
                                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <span>View Only Mode: Your account has Level 1 (View Only) access and cannot modify work orders.</span>
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

                            <div class="pt-3 flex items-center justify-between border-t border-slate-100">
                                <a id="edit_wo_print_btn" href="#" target="_blank" 
                                   class="bg-slate-100 hover:bg-[#FFE8D5] text-slate-700 hover:text-[#EB3E0B] font-bold text-xs px-4 py-2.5 rounded-full border border-slate-200 flex items-center space-x-1.5 transition-all">
                                    <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                    </svg>
                                    <span>Print PDF</span>
                                </a>
                                <div class="flex items-center space-x-2">
                                    <button type="button" onclick="closeEditWorkOrderModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                                        Cancel
                                    </button>
                                    <?php if ($my_tier === 1): ?>
                                        <button type="button" disabled class="bg-slate-300 text-slate-500 font-bold text-xs px-6 py-2.5 rounded-full cursor-not-allowed">
                                            🔒 View Only
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all">
                                            Update Work Order
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                function openEditAccountModal() {
                    var modal = document.getElementById('editAccountModal');
                    if (modal) modal.classList.remove('hidden');
                }
                function closeEditAccountModal() {
                    var modal = document.getElementById('editAccountModal');
                    if (modal) modal.classList.add('hidden');
                }

                function openServiceNoteDetailsModal(btn) {
                    try {
                        var raw = btn.getAttribute('data-note');
                        var tn = typeof raw === 'string' ? JSON.parse(raw) : raw;
                        document.getElementById('v_note_id').innerText = '#' + (tn.id || '0');
                        document.getElementById('v_note_date').innerText = tn.xdate || '';
                        document.getElementById('v_note_tech').innerText = tn.techname || '';
                        document.getElementById('v_note_status').innerText = tn.status || '';
                        document.getElementById('v_note_reason').innerText = tn.reasonoftech ? tn.reasonoftech : 'N/A';
                        document.getElementById('v_note_cause').innerText = tn.causeoftheissue ? tn.causeoftheissue : 'N/A';
                        document.getElementById('v_note_resso').innerText = tn.resso ? tn.resso : 'N/A';
                        
                        var printBtn = document.getElementById('v_note_print_btn');
                        if (printBtn && tn.id) {
                            printBtn.href = 'print_document.php?type=technote&id=' + encodeURIComponent(tn.id) + '&autoprint=1';
                        }
                        
                        var modal = document.getElementById('viewServiceNoteModal');
                        if (modal) modal.classList.remove('hidden');
                    } catch(e) {
                        console.error('Error opening note modal:', e);
                    }
                }

                function closeServiceNoteModal() {
                    var modal = document.getElementById('viewServiceNoteModal');
                    if (modal) modal.classList.add('hidden');
                }

                function openCreateWorkOrderModalFromBtn(btn) {
                    var acct = btn ? btn.getAttribute('data-acct') : '';
                    var name = btn ? btn.getAttribute('data-name') : '';
                    var addr = btn ? btn.getAttribute('data-addr') : '';
                    openCreateWorkOrderModal(acct, name, addr);
                }

                function openCreateWorkOrderModal(acct, name, addr) {
                    var mAcct = document.getElementById('create_wo_accountnum');
                    var mName = document.getElementById('create_wo_clientname');
                    var mAddr = document.getElementById('create_wo_address');
                    if (mAcct) mAcct.value = acct || '';
                    if (mName) mName.value = name || '';
                    if (mAddr) mAddr.value = addr || '';
                    var modal = document.getElementById('createWorkOrderModal');
                    if (modal) modal.classList.remove('hidden');
                }

                function closeCreateWorkOrderModal() {
                    var modal = document.getElementById('createWorkOrderModal');
                    if (modal) modal.classList.add('hidden');
                }

                function appendWoNature(text) {
                    var field = document.getElementById('create_wo_natureofwork');
                    if (!field) return;
                    if (field.value.trim() === '') {
                        field.value = text;
                    } else {
                        field.value += ', ' + text;
                    }
                }

                function openEditWorkOrderModal(btn) {
                    try {
                        var raw = btn.getAttribute('data-wo');
                        var wo = typeof raw === 'string' ? JSON.parse(raw) : raw;
                        document.getElementById('edit_wo_id').value = wo.id || '0';
                        document.getElementById('edit_wo_title_id').innerText = '#WO-' + (wo.id || '0');
                        
                        var mClient = document.getElementById('edit_wo_clientname');
                        if (mClient) mClient.value = wo.clientname || '';
                        
                        var mAddr = document.getElementById('edit_wo_address');
                        if (mAddr) mAddr.value = wo.address || '';

                        document.getElementById('edit_wo_xdate').value = wo.xdate || '';
                        document.getElementById('edit_wo_natureofwork').value = wo.natureofwork || '';
                        document.getElementById('edit_wo_amount').value = wo.amount || '0.00';
                        document.getElementById('edit_wo_ornum').value = wo.ornum || '';
                        document.getElementById('edit_wo_status').value = (wo.status || 'paid').toLowerCase();
                        
                        var printBtn = document.getElementById('edit_wo_print_btn');
                        if (printBtn && wo.id) {
                            printBtn.href = 'print_document.php?type=workorder&id=' + encodeURIComponent(wo.id) + '&autoprint=1';
                        }
                        
                        var modal = document.getElementById('editWorkOrderModal');
                        if (modal) modal.classList.remove('hidden');
                    } catch(e) {
                        console.error('Error opening work order edit modal:', e);
                    }
                }

                function closeEditWorkOrderModal() {
                    var modal = document.getElementById('editWorkOrderModal');
                    if (modal) modal.classList.add('hidden');
                }
                </script>

            <?php endif; ?>

                <!-- ADD NEW CLIENT MODAL -->
                <div id="createClientModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
                    <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto">
                        <button onclick="closeCreateClientModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="flex items-center space-x-3 mb-6">
                            <div class="w-10 h-10 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold shadow-sm">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-extrabold text-slate-900">Register New Client Account</h3>
                                <p class="text-xs text-slate-500">Creates a new record in the bucket_client table.</p>
                            </div>
                        </div>

                        <form action="accounts.php" method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="create_client">

                            <div class="p-3.5 bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl text-[11px] text-[#7C2112] font-medium flex items-start space-x-2">
                                <svg class="w-4 h-4 text-[#EB3E0B] shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>The client signs in to the portal with their <strong>Trade Business Name</strong> as the username and their <strong>Account Number</strong> as the password. Both values below become their login credentials.</span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Account Number *</label>
                                    <input type="text" name="accountnum" value="<?php echo sanitize(cf_val($cf, 'accountnum', $next_accountnum)); ?>" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                    <p class="text-[10px] text-slate-400 mt-1">A free number is pre-filled. Must be unique - it doubles as the client's password.</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Client Type</label>
                                    <input type="text" name="type" value="<?php echo sanitize(cf_val($cf, 'type', 'Client')); ?>" placeholder="POS Client / Standard" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Trade Business Name *</label>
                                    <input type="text" name="tradename" value="<?php echo sanitize(cf_val($cf, 'tradename')); ?>" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Owner / Client Name *</label>
                                    <input type="text" name="clientname" value="<?php echo sanitize(cf_val($cf, 'clientname')); ?>" required class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Contact Number</label>
                                    <input type="text" name="contactnum" value="<?php echo sanitize(cf_val($cf, 'contactnum')); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Email Address</label>
                                    <input type="email" name="emailaddress" value="<?php echo sanitize(cf_val($cf, 'emailaddress')); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Physical Address</label>
                                <textarea name="address" rows="2" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"><?php echo sanitize(cf_val($cf, 'address')); ?></textarea>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Monthly Retainer Fee</label>
                                    <input type="number" step="0.01" min="0" name="monthlyretainersfee" value="<?php echo sanitize(cf_val($cf, 'monthlyretainersfee', '0.00')); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Outstanding Balance</label>
                                    <input type="number" step="0.01" min="0" name="outstandingbalance" value="<?php echo sanitize(cf_val($cf, 'outstandingbalance', '0.00')); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 font-mono focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                </div>
                            </div>

                            <div class="pt-4 border-t border-slate-100 space-y-4">
                                <p class="text-xs font-extrabold text-slate-700 uppercase tracking-wider">Warranty Coverage (Optional)</p>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Status</label>
                                        <select name="warranty_status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                            <option value="Inactive"<?php echo cf_sel($cf, 'warranty_status', 'Inactive', 'Inactive'); ?>>Inactive</option>
                                            <option value="Active"<?php echo cf_sel($cf, 'warranty_status', 'Active', 'Inactive'); ?>>Active</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Coverage</label>
                                        <select name="warranty_coverage_type" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                            <option value="Both"<?php echo cf_sel($cf, 'warranty_coverage_type', 'Both', 'Both'); ?>>Both</option>
                                            <option value="Hardware"<?php echo cf_sel($cf, 'warranty_coverage_type', 'Hardware', 'Both'); ?>>Hardware</option>
                                            <option value="Software"<?php echo cf_sel($cf, 'warranty_coverage_type', 'Software', 'Both'); ?>>Software</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Expiry Date</label>
                                        <input type="date" name="warranty_expiry" value="<?php echo sanitize(cf_val($cf, 'warranty_expiry')); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Warranty Notes</label>
                                    <textarea name="warranty_notes" rows="2" placeholder="e.g. Full Hardware &amp; POS System Warranty Coverage" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"><?php echo sanitize(cf_val($cf, 'warranty_notes')); ?></textarea>
                                </div>
                            </div>

                            <?php if ($my_tier === 2): ?>
                                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                                    <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        <span>Security Access Code Required (Level 2 Account)</span>
                                    </label>
                                    <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                                </div>
                            <?php endif; ?>

                            <div class="pt-4 flex items-center justify-end space-x-3 border-t border-slate-100">
                                <button type="button" onclick="closeCreateClientModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                                    Cancel
                                </button>
                                <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">
                                    Register Client
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                function openCreateClientModal() {
                    var modal = document.getElementById('createClientModal');
                    if (modal) modal.classList.remove('hidden');
                }
                function closeCreateClientModal() {
                    var modal = document.getElementById('createClientModal');
                    if (modal) modal.classList.add('hidden');
                }
                <?php if (!empty($cf)): ?>
                // A submission was rejected - reopen the form with the entered values intact
                document.addEventListener('DOMContentLoaded', openCreateClientModal);
                <?php endif; ?>
                </script>

<script>
function handleAccountSearchInput(val) {
    var dropdown = document.getElementById('accountAutocompleteDropdown');
    if (!dropdown || typeof ALL_ACCOUNTS === 'undefined') return;

    var query = (val || '').trim().toLowerCase();

    if (query.length === 0) {
        dropdown.classList.add('hidden');
        dropdown.innerHTML = '';
        return;
    }

    var matches = ALL_ACCOUNTS.filter(function(acct) {
        var num = (acct.accountnum || '').toLowerCase();
        var trade = (acct.tradename || '').toLowerCase();
        var client = (acct.clientname || '').toLowerCase();
        return num.indexOf(query) !== -1 || trade.indexOf(query) !== -1 || client.indexOf(query) !== -1;
    });

    if (matches.length === 0) {
        dropdown.innerHTML = '<div class="p-4 text-center text-xs text-slate-400 font-medium">No matching client accounts found</div>';
        dropdown.classList.remove('hidden');
        return;
    }

    var html = '';
    matches.forEach(function(acct) {
        var tradeLabel = acct.tradename ? acct.tradename : acct.clientname;
        var ownerLabel = acct.clientname ? acct.clientname : '';
        html += '<a href="accounts.php?q=' + encodeURIComponent(acct.accountnum) + '" class="flex items-center justify-between p-3.5 hover:bg-[#FFE8D5] transition-colors group cursor-pointer text-left block text-slate-900 border-b border-slate-100 last:border-0">';
        html += '  <div class="flex items-center space-x-3 overflow-hidden">';
        html += '    <div class="w-8 h-8 rounded-xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-sm">';
        html +=        escapeHtml((tradeLabel.charAt(0) || 'C').toUpperCase());
        html += '    </div>';
        html += '    <div class="truncate">';
        html += '      <p class="text-xs font-extrabold text-slate-900 group-hover:text-[#EB3E0B] truncate">' + escapeHtml(tradeLabel) + '</p>';
        html += '      <p class="text-[11px] text-slate-500 truncate">Owner: ' + escapeHtml(ownerLabel) + '</p>';
        html += '    </div>';
        html += '  </div>';
        html += '  <span class="text-xs font-mono font-bold bg-slate-100 group-hover:bg-white text-[#EB3E0B] px-2.5 py-1 rounded-full border border-slate-200/80 shrink-0 ml-2">';
        html +=      escapeHtml(acct.accountnum);
        html += '  </span>';
        html += '</a>';
    });

    dropdown.innerHTML = html;
    dropdown.classList.remove('hidden');
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('click', function(e) {
    var searchBox = document.getElementById('accountSearchInput');
    var dropdown = document.getElementById('accountAutocompleteDropdown');
    if (searchBox && dropdown && !searchBox.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.add('hidden');
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
