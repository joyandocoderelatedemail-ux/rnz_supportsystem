<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db-config.php';

function rnz_database_name()
{
    return preg_replace('/[^a-zA-Z0-9_]/', '', RNZ_DB_NAME);
}

function rnz_db_connect()
{
    static $connection = null;

    if ($connection) {
        return $connection;
    }

    $dbName = rnz_database_name();
    if ($dbName === '') {
        error_log('RNZ database name is invalid.');
        return false;
    }

    // Try direct connection to database
    $connection = @mysqli_connect(RNZ_DB_HOST, RNZ_DB_USER, RNZ_DB_PASS, $dbName, RNZ_DB_PORT);
    if (!$connection && RNZ_DB_USER === 'root') {
        // Local fallback
        $connection = @mysqli_connect(RNZ_DB_HOST, RNZ_DB_USER, RNZ_DB_PASS, 'rnz_supportsystem', RNZ_DB_PORT);
        if (!$connection) {
            $tempConn = @mysqli_connect(RNZ_DB_HOST, RNZ_DB_USER, RNZ_DB_PASS, '', RNZ_DB_PORT);
            if ($tempConn) {
                @mysqli_query($tempConn, 'CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                @mysqli_close($tempConn);
                $connection = @mysqli_connect(RNZ_DB_HOST, RNZ_DB_USER, RNZ_DB_PASS, $dbName, RNZ_DB_PORT);
            }
        }
    }

    if (!$connection) {
        error_log('RNZ MySQL connection failed: ' . mysqli_connect_error());
        return false;
    }

    mysqli_set_charset($connection, 'utf8mb4');

    if (!rnz_ensure_demo_table($connection)) {
        mysqli_close($connection);
        $connection = null;
        return false;
    }

    rnz_import_json_requests($connection);

    return $connection;
}

function rnz_ensure_demo_table($connection)
{
    $sql = "CREATE TABLE IF NOT EXISTS demo_requests (
        id VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'New',
        name VARCHAR(160) NOT NULL,
        contact VARCHAR(80) NOT NULL,
        location VARCHAR(180) NOT NULL,
        preferred_pos VARCHAR(80) NOT NULL,
        other_system VARCHAR(160) NOT NULL DEFAULT '',
        notes TEXT NULL,
        PRIMARY KEY (id),
        KEY idx_created_at (created_at),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!mysqli_query($connection, $sql)) {
        error_log('RNZ table create failed: ' . mysqli_error($connection));
        return false;
    }

    return true;
}

function rnz_generate_request_id()
{
    return 'RNZ-' . date('Ymd-His') . '-' . mt_rand(1000, 9999);
}

function rnz_insert_demo_request($request)
{
    $connection = rnz_db_connect();
    if (!$connection) {
        return false;
    }

    $sql = 'INSERT INTO demo_requests
        (id, created_at, updated_at, status, name, contact, location, preferred_pos, other_system, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = mysqli_prepare($connection, $sql);
    if (!$stmt) {
        error_log('RNZ insert prepare failed: ' . mysqli_error($connection));
        return false;
    }

    $id = isset($request['id']) ? (string)$request['id'] : rnz_generate_request_id();
    $createdAt = isset($request['created_at']) ? (string)$request['created_at'] : date('Y-m-d H:i:s');
    $updatedAt = isset($request['updated_at']) ? (string)$request['updated_at'] : null;
    $status = isset($request['status']) ? (string)$request['status'] : 'New';
    $name = isset($request['name']) ? (string)$request['name'] : '';
    $contact = isset($request['contact']) ? (string)$request['contact'] : '';
    $location = isset($request['location']) ? (string)$request['location'] : '';
    $preferredPos = isset($request['preferred_pos']) ? (string)$request['preferred_pos'] : '';
    $otherSystem = isset($request['other_system']) ? (string)$request['other_system'] : '';
    $notes = isset($request['notes']) ? (string)$request['notes'] : '';

    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssss',
        $id,
        $createdAt,
        $updatedAt,
        $status,
        $name,
        $contact,
        $location,
        $preferredPos,
        $otherSystem,
        $notes
    );

    $saved = mysqli_stmt_execute($stmt);
    if (!$saved) {
        error_log('RNZ insert failed: ' . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);
    return $saved;
}

function rnz_create_demo_request($name, $contact, $location, $preferredPos, $otherSystem, $notes)
{
    return rnz_insert_demo_request(array(
        'id' => rnz_generate_request_id(),
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'New',
        'name' => $name,
        'contact' => $contact,
        'location' => $location,
        'preferred_pos' => $preferredPos,
        'other_system' => $otherSystem,
        'notes' => $notes,
    ));
}

function rnz_load_requests()
{
    $connection = rnz_db_connect();
    if (!$connection) {
        return array();
    }

    $requests = array();
    $sql = 'SELECT id, created_at, updated_at, status, name, contact, location, preferred_pos, other_system, notes
        FROM demo_requests
        ORDER BY created_at DESC, id DESC';
    $result = mysqli_query($connection, $sql);

    if (!$result) {
        error_log('RNZ select failed: ' . mysqli_error($connection));
        return $requests;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $requests[] = $row;
    }

    mysqli_free_result($result);
    return $requests;
}

function rnz_update_request_status($requestId, $status)
{
    $connection = rnz_db_connect();
    if (!$connection) {
        return false;
    }

    $updatedAt = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($connection, 'UPDATE demo_requests SET status = ?, updated_at = ? WHERE id = ?');
    if (!$stmt) {
        error_log('RNZ update prepare failed: ' . mysqli_error($connection));
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'sss', $status, $updatedAt, $requestId);
    $updated = mysqli_stmt_execute($stmt);
    if (!$updated) {
        error_log('RNZ update failed: ' . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);
    return $updated;
}

function rnz_delete_request($requestId)
{
    $connection = rnz_db_connect();
    if (!$connection) {
        return false;
    }

    $stmt = mysqli_prepare($connection, 'DELETE FROM demo_requests WHERE id = ?');
    if (!$stmt) {
        error_log('RNZ delete prepare failed: ' . mysqli_error($connection));
        return false;
    }

    mysqli_stmt_bind_param($stmt, 's', $requestId);
    $deleted = mysqli_stmt_execute($stmt);
    if (!$deleted) {
        error_log('RNZ delete failed: ' . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);
    return $deleted;
}

function rnz_import_json_requests($connection)
{
    static $imported = false;

    if ($imported) {
        return;
    }
    $imported = true;

    $dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    $dataFile = $dataDir . DIRECTORY_SEPARATOR . 'demo-requests.json';
    $lockFile = $dataDir . DIRECTORY_SEPARATOR . 'demo-requests.imported';
    if (file_exists($lockFile)) {
        return;
    }
    if (!file_exists($dataFile)) {
        return;
    }

    $countResult = mysqli_query($connection, 'SELECT COUNT(*) AS total FROM demo_requests');
    if ($countResult) {
        $countRow = mysqli_fetch_assoc($countResult);
        mysqli_free_result($countResult);
        if ($countRow && (int)$countRow['total'] > 0) {
            return;
        }
    }

    $rawJson = file_get_contents($dataFile);
    $requests = $rawJson ? json_decode($rawJson, true) : array();
    if (!is_array($requests)) {
        return;
    }

    foreach ($requests as $request) {
        if (is_array($request) && !empty($request['id'])) {
            rnz_insert_demo_request($request);
        }
    }

    @file_put_contents($lockFile, date('c'));
}
