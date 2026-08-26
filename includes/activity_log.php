<?php
declare(strict_types=1);

// Activity logging system — logs staff actions to CSV files in /logs folder.
// No database changes required.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Use Philippine timezone for all timestamps
date_default_timezone_set('Asia/Manila');

define('STAFF_LIST', [
    'Mark Angelo',
    'Mary Grace',
    'Dannah Kryzle',
    'Charen Charise',
    'Rhelyn',
    'Darylle Vincent',
    'Clydene Rose',
    'Norberto Jr',
    'John Roe',
    'Norman Paul',
    'Galo Rowe',
]);

define('LOGS_DIR', __DIR__ . '/../logs');

// Ensure logs directory exists
if (!is_dir(LOGS_DIR)) {
    mkdir(LOGS_DIR, 0755, true);
}

// Session-based staff tracking
function current_staff(): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['staff_name'] ?? '';
}

function set_current_staff(string $name): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['staff_name'] = $name;
}

function require_staff(): void
{
    // Force re-login if the app was restarted (new server session)
    $bootFile = __DIR__ . '/../logs/.boot-id';
    $currentBoot = file_exists($bootFile) ? trim(file_get_contents($bootFile)) : '';
    if (session_status() === PHP_SESSION_NONE) session_start();

    if ($currentBoot !== '' && ($_SESSION['boot_id'] ?? '') !== $currentBoot) {
        // App was restarted — clear staff session, force re-pick
        unset($_SESSION['staff_name']);
        $_SESSION['boot_id'] = $currentBoot;
    }

    if (current_staff() === '') {
        header('Location: staff_select.php');
        exit;
    }
}

// Log an activity to CSV
// Format: timestamp, staff_name, action, entity_type, entity_id, details
function log_activity(string $action, string $entityType = '', string $entityId = '', string $details = ''): void
{
    $staff = current_staff();
    if ($staff === '') return;

    $logFile = LOGS_DIR . '/activity-log.csv';

    // Create header if file doesn't exist
    if (!file_exists($logFile)) {
        file_put_contents($logFile, "timestamp,staff_name,action,entity_type,entity_id,details\n");
    }

    $row = [
        date('Y-m-d H:i:s'),
        $staff,
        $action,
        $entityType,
        $entityId,
        $details,
    ];

    $fp = fopen($logFile, 'a');
    if ($fp) {
        fputcsv($fp, $row);
        fclose($fp);
    }
}

// Read all log entries (returns array of associative arrays)
function read_activity_log(): array
{
    $logFile = LOGS_DIR . '/activity-log.csv';
    if (!file_exists($logFile)) return [];

    $entries = [];
    $fp = fopen($logFile, 'r');
    if (!$fp) return [];

    $headers = fgetcsv($fp); // skip header row
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) >= 6) {
            $entries[] = [
                'timestamp'   => $row[0],
                'staff_name'  => $row[1],
                'action'      => $row[2],
                'entity_type' => $row[3],
                'entity_id'   => $row[4],
                'details'     => $row[5],
            ];
        }
    }
    fclose($fp);
    return $entries;
}
