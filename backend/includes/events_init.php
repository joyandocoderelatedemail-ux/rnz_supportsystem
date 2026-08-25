<?php
// Events & Interactive Schedule Initializer (PHP 5.6 Compatible)
require_once __DIR__ . '/config.php';

/**
 * Initialize support_events table if not already existing
 * @return bool
 */
function init_events_table() {
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $sql = "CREATE TABLE IF NOT EXISTS `support_events` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL,
            `event_type` VARCHAR(50) NOT NULL DEFAULT 'Field Visit',
            `accountnum` VARCHAR(50) NULL DEFAULT NULL,
            `client_name` VARCHAR(255) NULL DEFAULT NULL,
            `assigned_tech` VARCHAR(100) NULL DEFAULT NULL,
            `assigned_user_id` INT(11) NULL DEFAULT NULL,
            `location` VARCHAR(255) NULL DEFAULT NULL,
            `start_datetime` DATETIME NOT NULL,
            `end_datetime` DATETIME NOT NULL,
            `all_day` TINYINT(1) NOT NULL DEFAULT 0,
            `priority` VARCHAR(20) NOT NULL DEFAULT 'Medium',
            `status` VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
            `description` TEXT NULL,
            `color` VARCHAR(30) NULL DEFAULT NULL,
            `created_by` VARCHAR(100) NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `start_datetime` (`start_datetime`),
            KEY `end_datetime` (`end_datetime`),
            KEY `accountnum` (`accountnum`),
            KEY `assigned_tech` (`assigned_tech`),
            KEY `event_type` (`event_type`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("Events table init error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get category metadata (colors, badges) for event types
 * @param string $type
 * @return array
 */
function get_event_type_meta($type) {
    $t = trim($type);
    switch ($t) {
        case 'POS Installation':
            return array(
                'label' => 'POS Installation',
                'bg_hex' => '#059669',
                'border_hex' => '#10B981',
                'text_hex' => '#FFFFFF',
                'badge_class' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                'dot_class' => 'bg-emerald-500'
            );
        case 'POS Maintenance':
            return array(
                'label' => 'POS Maintenance',
                'bg_hex' => '#2563EB',
                'border_hex' => '#3B82F6',
                'text_hex' => '#FFFFFF',
                'badge_class' => 'bg-blue-100 text-blue-800 border-blue-300',
                'dot_class' => 'bg-blue-500'
            );
        case 'Hardware Delivery':
            return array(
                'label' => 'Hardware Delivery',
                'bg_hex' => '#7C3AED',
                'border_hex' => '#8B5CF6',
                'text_hex' => '#FFFFFF',
                'badge_class' => 'bg-purple-100 text-purple-800 border-purple-300',
                'dot_class' => 'bg-purple-500'
            );
        case 'Urgent Troubleshooting':
            return array(
                'label' => 'Urgent Troubleshooting',
                'bg_hex' => '#DC2626',
                'border_hex' => '#EF4444',
                'text_hex' => '#FFFFFF',
                'badge_class' => 'bg-rose-100 text-rose-800 border-rose-300',
                'dot_class' => 'bg-rose-500'
            );
        case 'System Upgrade':
            return array(
                'label' => 'System Upgrade',
                'bg_hex' => '#0891B2',
                'border_hex' => '#06B6D4',
                'text_hex' => '#FFFFFF',
                'badge_class' => 'bg-cyan-100 text-cyan-800 border-cyan-300',
                'dot_class' => 'bg-cyan-500'
            );
        case 'Meeting / Conference':
            return array(
                'label' => 'Meeting / Conference',
                'bg_hex' => '#D97706',
                'border_hex' => '#F59E0B',
                'text_hex' => '#FFFFFF',
                'badge_class' => 'bg-amber-100 text-amber-800 border-amber-300',
                'dot_class' => 'bg-amber-500'
            );
        case 'General Reminder':
            return array(
                'label' => 'General Reminder',
                'bg_hex' => '#475569',
                'border_hex' => '#64748B',
                'text_hex' => '#FFFFFF',
                'badge_class' => 'bg-slate-100 text-slate-800 border-slate-300',
                'dot_class' => 'bg-slate-500'
            );
        case 'Field Visit':
        default:
            return array(
                'label' => !empty($t) ? $t : 'Field Visit',
                'bg_hex' => '#EB3E0B',
                'border_hex' => '#FA5915',
                'text_hex' => '#FFFFFF',
                'badge_class' => 'bg-orange-100 text-[#C32C0B] border-orange-300',
                'dot_class' => 'bg-[#EB3E0B]'
            );
    }
}

/**
 * Get status badge classes for event schedules
 * @param string $status
 * @return string
 */
function get_event_status_badge($status) {
    $s = trim($status);
    switch ($s) {
        case 'Completed':
            return 'bg-emerald-100 text-emerald-800 border border-emerald-300';
        case 'In Progress':
            return 'bg-blue-100 text-blue-800 border border-blue-300';
        case 'Cancelled':
            return 'bg-slate-100 text-slate-600 border border-slate-300 line-through';
        case 'Rescheduled':
            return 'bg-amber-100 text-amber-900 border border-amber-300';
        case 'Scheduled':
        default:
            return 'bg-orange-100 text-[#C32C0B] border border-orange-300';
    }
}
