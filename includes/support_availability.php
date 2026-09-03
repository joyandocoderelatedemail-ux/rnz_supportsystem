<?php
// Support Availability & Automatic First Reply (PHP 5.6 Compatible)
//
// A ticket raised while no technician is signed into the support center gets
// an automatic answer in its chat thread, so the client is not left waiting on
// a silent conversation. Hardware and software requests both funnel through
// the new ticket form on tickets.php, so both are covered from there.
//
// Presence itself is written by the backend into support_user_presence; this
// side only reads it.
require_once __DIR__ . '/config.php';

// Matches PRESENCE_ONLINE_MINUTES in backend/includes/presence_init.php - a
// technician counts as online while the backend saw them within this window.
define('SUPPORT_ONLINE_WINDOW_MINUTES', 5);

// Name the automatic message is signed with in the chat thread.
define('SUPPORT_AUTOREPLY_SENDER', 'RNZ Support');

/**
 * How many technicians or admins are signed into the support center right now.
 *
 * @param PDO $pdo
 * @return int
 */
function count_online_support_staff($pdo) {
    if (!$pdo) {
        return 0;
    }
    // Worked out in PHP because last_activity is written with PHP time - the
    // database clock can sit in another zone on a live server.
    $cutoff = date('Y-m-d H:i:s', time() - (SUPPORT_ONLINE_WINDOW_MINUTES * 60));

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `support_user_presence` WHERE `last_activity` >= :cutoff");
        $stmt->execute(array(':cutoff' => $cutoff));
        return intval($stmt->fetchColumn());
    } catch (PDOException $e) {
        // The table is created the first time the backend is opened. Missing
        // means nobody has ever signed in, which is still nobody online.
        return 0;
    }
}

/**
 * The technicians signed into the support center right now, for the client
 * portal's availability panel. Same window as count_online_support_staff, so
 * what the client is shown and what the ticket auto-reply says always agree.
 *
 * @param PDO $pdo
 * @return array list of array('name', 'role', 'initials', 'ago')
 */
function get_available_support_staff($pdo) {
    if (!$pdo) {
        return array();
    }
    $cutoff = date('Y-m-d H:i:s', time() - (SUPPORT_ONLINE_WINDOW_MINUTES * 60));

    try {
        $stmt = $pdo->prepare("SELECT p.fullname, p.username, p.accesslevel, p.last_activity,
                u.fname, u.lname
            FROM `support_user_presence` p
            LEFT JOIN `user` u ON u.id = p.user_id
            WHERE p.last_activity >= :cutoff
            ORDER BY p.last_activity DESC");
        $stmt->execute(array(':cutoff' => $cutoff));
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table is created by the backend on first sign-in; missing means
        // nobody has ever signed in, which is still nobody available.
        return array();
    }

    $staff = array();
    foreach ($rows as $r) {
        $name = trim($r['fullname']);
        if ($name === '') {
            $name = trim($r['fname'] . ' ' . $r['lname']);
        }
        if ($name === '') {
            $name = $r['username'];
        }
        if ($name === '') {
            continue;
        }
        $staff[] = array(
            'name'     => $name,
            'role'     => support_role_label($r['accesslevel']),
            'initials' => support_staff_initials($name),
            'ago'      => support_active_ago($r['last_activity'])
        );
    }
    return $staff;
}

/**
 * Backend access levels are internal wording; give the client something that
 * reads like a job title instead.
 *
 * @param string $accesslevel
 * @return string
 */
function support_role_label($accesslevel) {
    $lvl = strtolower(trim($accesslevel));
    if ($lvl === 'master' || $lvl === 'super admin' || $lvl === 'superadmin') {
        return 'Support Lead';
    }
    if ($lvl === 'admin' || $lvl === 'administrator') {
        return 'Senior Technician';
    }
    if (strpos($lvl, 'programmer') !== false) {
        return 'Systems Programmer';
    }
    return 'Technician';
}

/**
 * Two-letter avatar initials from a name.
 * @param string $name
 * @return string
 */
function support_staff_initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    if (empty($parts[0])) {
        return '?';
    }
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($parts[0], 0, 2));
}

/**
 * Short "last seen" label for an availability row, e.g. "active just now".
 * @param string $datetime
 * @return string
 */
function support_active_ago($datetime) {
    if (empty($datetime)) {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $secs = time() - $ts;
    if ($secs < 0) {
        $secs = 0;
    }
    if ($secs < 60) {
        return 'active just now';
    }
    return 'active ' . intval(round($secs / 60)) . 'm ago';
}

/**
 * Working hours per technician, in 24-hour time.
 * @return array name => array('start' => 'HH:MM', 'end' => 'HH:MM')
 */
function get_technician_hours() {
    return array(
        'Chin'      => array('start' => '08:00', 'end' => '17:00'),
        'Jerold'    => array('start' => '10:00', 'end' => '19:00'),
        'James'     => array('start' => '08:00', 'end' => '17:00'),
        'Christian' => array('start' => '08:00', 'end' => '17:00'),
        'Simon'     => array('start' => '08:00', 'end' => '17:00')
    );
}

/**
 * The technician duty roster shown to clients.
 * @return array
 */
function get_technician_schedule() {
    return array(
        'weekday'  => array('Chin', 'Jerold', 'James', 'Christian', 'Simon'),
        'saturday' => array('Christian', 'Simon')
    );
}

/**
 * Minutes past midnight for a HH:MM string.
 * @param string $hhmm
 * @return int
 */
function duty_minutes($hhmm) {
    $parts = explode(':', $hhmm);
    return (intval($parts[0]) * 60) + (isset($parts[1]) ? intval($parts[1]) : 0);
}

/**
 * Minutes past midnight rendered as a clock time, e.g. 480 -> "8:00 AM".
 * @param int $minutes
 * @return string
 */
function format_duty_minutes($minutes) {
    $minutes = intval($minutes);
    return date('g:i A', mktime(floor($minutes / 60), $minutes % 60, 0, 1, 1, 2000));
}

/**
 * A technician shift as the client reads it, e.g. "8:00 AM - 5:00 PM".
 * @param string $name
 * @return string empty when the name has no hours on file
 */
function format_technician_hours($name) {
    $hours = get_technician_hours();
    if (!isset($hours[$name])) {
        return '';
    }
    return date('g:i A', strtotime($hours[$name]['start'])) . ' - ' . date('g:i A', strtotime($hours[$name]['end']));
}

/**
 * Roster lines for one set of technicians, one per line.
 * @param array $names
 * @return string
 */
function format_duty_lines($names) {
    $out = '';
    foreach ($names as $n) {
        $span = format_technician_hours($n);
        $out .= '- ' . $n . ($span !== '' ? ': ' . $span : '') . "\n";
    }
    return $out;
}

/**
 * Technicians whose shift covers the given moment today.
 * @param int|null $timestamp
 * @return array
 */
function get_technicians_working_now($timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    $hours = get_technician_hours();
    $now_min = duty_minutes(date('H:i', $timestamp));

    $working = array();
    foreach (get_technicians_on_duty($timestamp) as $n) {
        if (!isset($hours[$n])) {
            continue;
        }
        if ($now_min >= duty_minutes($hours[$n]['start']) && $now_min < duty_minutes($hours[$n]['end'])) {
            $working[] = $n;
        }
    }
    return $working;
}

/**
 * The next day that has technicians rostered, worded so it can be dropped
 * straight into a sentence - the preposition is part of the phrase.
 *
 * @param int|null $timestamp
 * @return string e.g. "tomorrow (Saturday)" or "on Monday"
 */
function next_duty_day_phrase($timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    for ($i = 1; $i <= 7; $i++) {
        $day_ts = strtotime('+' . $i . ' day', $timestamp);
        $roster = get_technicians_on_duty($day_ts);
        if (!empty($roster)) {
            $day_name = date('l', $day_ts);
            return ($i === 1) ? 'tomorrow (' . $day_name . ')' : 'on ' . $day_name;
        }
    }
    return 'on the next working day';
}

/**
 * Technicians rostered for a given day. Sunday has no duty crew.
 *
 * @param int|null $timestamp defaults to now
 * @return array list of names, empty on Sunday
 */
function get_technicians_on_duty($timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    $schedule = get_technician_schedule();
    $day = intval(date('w', $timestamp)); // 0 = Sunday, 6 = Saturday

    if ($day === 0) {
        return array();
    }
    if ($day === 6) {
        return $schedule['saturday'];
    }
    return $schedule['weekday'];
}

/**
 * The message posted into a new ticket when no technician is online.
 *
 * @param int|null $timestamp defaults to now
 * @return string
 */
function build_offline_autoreply_message($timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    $schedule = get_technician_schedule();
    $on_duty = get_technicians_on_duty($timestamp);
    $day_name = date('l', $timestamp);
    $hours = get_technician_hours();

    $msg  = "Thank you for reaching out to RNZ Support. No technician is available at the moment, so this ticket has not been picked up yet.\n\n";
    $msg .= "Your request has been logged and a technician will assist you within 24 to 48 hours.\n\n";
    $msg .= "TECHNICIAN SCHEDULE\n";
    $msg .= "Monday to Friday\n";
    $msg .= format_duty_lines($schedule['weekday']);
    $msg .= "\nSaturday\n";
    $msg .= format_duty_lines($schedule['saturday']);
    $msg .= "\nSunday: No technician on duty\n\n";

    if (empty($on_duty)) {
        $msg .= "Today is " . $day_name . ", so no technician is on duty. The team picks this up " . next_duty_day_phrase($timestamp) . ".";
        return $msg;
    }

    // Within working hours the ticket only waits for someone to sign in;
    // outside them it waits for the next shift, so say which applies.
    $working_now = get_technicians_working_now($timestamp);
    if (!empty($working_now)) {
        $msg .= "On duty today (" . $day_name . "): " . implode(', ', $working_now) . ". They will attend to your ticket within their working hours.";
        return $msg;
    }

    $now_min = duty_minutes(date('H:i', $timestamp));
    $earliest = null;
    $latest = null;
    foreach ($on_duty as $n) {
        if (!isset($hours[$n])) {
            continue;
        }
        $start = duty_minutes($hours[$n]['start']);
        $end = duty_minutes($hours[$n]['end']);
        if ($earliest === null || $start < $earliest) {
            $earliest = $start;
        }
        if ($latest === null || $end > $latest) {
            $latest = $end;
        }
    }

    if ($earliest !== null && $now_min < $earliest) {
        $msg .= "Support hours today (" . $day_name . ") start at " . format_duty_minutes($earliest) . ", and your ticket will be picked up from then.";
    } else {
        $msg .= "Support hours for today (" . $day_name . ") have already ended. Your ticket will be picked up " . next_duty_day_phrase($timestamp) . ".";
    }

    return $msg;
}

/**
 * The message posted into a new ticket while a technician IS online - the
 * counterpart to build_offline_autoreply_message(). Tells the client they have
 * been picked up and to give the technician a few minutes.
 *
 * @param array $staff rows from get_available_support_staff()
 * @return string
 */
function build_online_ack_message($staff) {
    $count = count($staff);

    $msg  = "Thank you for reaching out to RNZ Support. Your ticket has been received and a technician is online right now.\n\n";

    if ($count > 0) {
        $msg .= ($count === 1) ? "AVAILABLE TECHNICIAN\n" : "AVAILABLE TECHNICIANS\n";
        foreach ($staff as $s) {
            $msg .= '- ' . $s['name'] . ' (' . $s['role'] . ")\n";
        }
        $msg .= "\n";
    }

    $msg .= "You will be assisted shortly. Please stay on this chat and allow a few minutes while the technician reviews your request - your reply will appear right here.";
    return $msg;
}

/**
 * Post the "someone is on it" reply on a brand new ticket while at least one
 * technician is signed into the support center. Does nothing when nobody is
 * online - send_offline_autoreply_if_unattended() covers that case.
 *
 * @param PDO $pdo
 * @param int $ticket_id
 * @return bool true when the message was posted
 */
function send_online_ack_if_attended($pdo, $ticket_id) {
    $ticket_id = intval($ticket_id);
    if (!$pdo || $ticket_id <= 0) {
        return false;
    }

    $staff = get_available_support_staff($pdo);
    if (empty($staff)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO client_ticket_replies
            (ticket_id, sender_type, sender_name, message, created_at)
            VALUES (:tid, 'support', :sname, :msg, :c_at)");
        $stmt->execute(array(
            ':tid'   => $ticket_id,
            ':sname' => SUPPORT_AUTOREPLY_SENDER,
            ':msg'   => build_online_ack_message($staff),
            ':c_at'  => date('Y-m-d H:i:s')
        ));
        return true;
    } catch (PDOException $e) {
        error_log("Online ack reply error: " . $e->getMessage());
        return false;
    }
}

/**
 * Post the automatic reply on a brand new ticket when nobody is online to take
 * it. Does nothing while at least one technician is signed in.
 *
 * @param PDO $pdo
 * @param int $ticket_id
 * @return bool true when the message was posted
 */
function send_offline_autoreply_if_unattended($pdo, $ticket_id) {
    $ticket_id = intval($ticket_id);
    if (!$pdo || $ticket_id <= 0) {
        return false;
    }
    if (count_online_support_staff($pdo) > 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO client_ticket_replies
            (ticket_id, sender_type, sender_name, message, created_at)
            VALUES (:tid, 'support', :sname, :msg, :c_at)");
        $stmt->execute(array(
            ':tid'   => $ticket_id,
            ':sname' => SUPPORT_AUTOREPLY_SENDER,
            ':msg'   => build_offline_autoreply_message(),
            ':c_at'  => date('Y-m-d H:i:s')
        ));
        return true;
    } catch (PDOException $e) {
        error_log("Offline auto-reply error: " . $e->getMessage());
        return false;
    }
}
