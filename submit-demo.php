<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'demo-storage.php';

date_default_timezone_set('Asia/Manila');

function redirect_with_status($status)
{
    header('Location: ./index.html?demo=' . urlencode($status) . '#demo');
    exit;
}

function clean_input($value, $maxLength)
{
    $value = trim(strip_tags((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    if ($value === null) {
        $value = '';
    }
    return substr($value, 0, $maxLength);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_status('error');
}

$allowedSystems = array(
    'Retail',
    'Restaurant',
    'Warehouse Systems',
    'Company Software',
    'Payroll',
    'Payroll System',
    'Accounting System',
    'Website Development',
    'Account Management System',
    'Web Hosting',
    'Custom System',
    'Others',
);

$name = clean_input(isset($_POST['name']) ? $_POST['name'] : '', 160);
$contact = clean_input(isset($_POST['contact']) ? $_POST['contact'] : '', 80);
$location = clean_input(isset($_POST['location']) ? $_POST['location'] : '', 180);
$preferredPos = clean_input(isset($_POST['preferred_pos']) ? $_POST['preferred_pos'] : '', 80);
$otherSystem = clean_input(isset($_POST['other_system']) ? $_POST['other_system'] : '', 160);
$notes = clean_input(isset($_POST['notes']) ? $_POST['notes'] : '', 800);

if (
    $name === '' ||
    $contact === '' ||
    $location === '' ||
    !in_array($preferredPos, $allowedSystems, true) ||
    ($preferredPos === 'Others' && $otherSystem === '')
) {
    redirect_with_status('error');
}

$saved = rnz_create_demo_request($name, $contact, $location, $preferredPos, $otherSystem, $notes);

redirect_with_status($saved ? 'success' : 'error');
