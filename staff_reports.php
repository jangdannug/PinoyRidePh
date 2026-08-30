<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$tabTitle = 'Staff Reports';
$activeNav = 'reports';

$entries = read_activity_log();

// ---- CSV Export ----
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pinoyride-report-' . $exportType . '-' . date('Y-m-d') . '.csv"');
    $fp = fopen('php://output', 'w');

    if ($exportType === 'ingestion') {
        fputcsv($fp, ['Staff', 'Passengers Created', 'Drivers Created', 'Total']);
        $ingestionActions = ['create_passenger', 'create_driver'];
        $ingestions = array_filter($entries, fn($e) => in_array($e['action'], $ingestionActions, true));
        $byStaff = [];
        foreach ($ingestions as $e) {
            $name = $e['staff_name'];
            if (!isset($byStaff[$name])) $byStaff[$name] = ['passengers' => 0, 'drivers' => 0, 'total' => 0];
            if ($e['action'] === 'create_passenger') $byStaff[$name]['passengers']++;
            if ($e['action'] === 'create_driver') $byStaff[$name]['drivers']++;
            $byStaff[$name]['total']++;
        }
        foreach ($byStaff as $name => $c) {
            fputcsv($fp, [$name, $c['passengers'], $c['drivers'], $c['total']]);
        }
    } elseif ($exportType === 'daily') {
        fputcsv($fp, ['Date', 'Staff', 'Passengers Created', 'Drivers Created', 'Total']);
        $ingestionActions = ['create_passenger', 'create_driver'];
        $ingestions = array_filter($entries, fn($e) => in_array($e['action'], $ingestionActions, true));
        $byDateStaff = [];
        foreach ($ingestions as $e) {
            $date = substr($e['timestamp'], 0, 10);
            $key = $date . '|' . $e['staff_name'];
            if (!isset($byDateStaff[$key])) $byDateStaff[$key] = ['date' => $date, 'staff' => $e['staff_name'], 'passengers' => 0, 'drivers' => 0, 'total' => 0];
            if ($e['action'] === 'create_passenger') $byDateStaff[$key]['passengers']++;
            if ($e['action'] === 'create_driver') $byDateStaff[$key]['drivers']++;
            $byDateStaff[$key]['total']++;
        }
        krsort($byDateStaff);
        foreach ($byDateStaff as $row) {
            fputcsv($fp, [$row['date'], $row['staff'], $row['passengers'], $row['drivers'], $row['total']]);
        }
    } elseif ($exportType === 'attendance') {
        fputcsv($fp, ['Date', 'Staff', 'First Login', 'Last Logout', 'Duration (minutes)']);
        $att = [];
        foreach ($entries as $e) {
            if ($e['action'] !== 'login' && $e['action'] !== 'logout') continue;
            $date = substr($e['timestamp'], 0, 10);
            $key  = $date . '|' . $e['staff_name'];
            if (!isset($att[$key])) {
                $att[$key] = ['date' => $date, 'staff' => $e['staff_name'], 'first' => null, 'last' => null];
            }
            if ($e['action'] === 'login' && ($att[$key]['first'] === null || $e['timestamp'] < $att[$key]['first'])) {
                $att[$key]['first'] = $e['timestamp'];
            }
            if ($e['action'] === 'logout' && ($att[$key]['last'] === null || $e['timestamp'] > $att[$key]['last'])) {
                $att[$key]['last'] = $e['timestamp'];
            }
        }
        usort($att, fn($a, $b) => [$b['date'], $a['staff']] <=> [$a['date'], $b['staff']]);
        foreach ($att as $r) {
            $lastOut = $r['last'];
            if ($r['first'] !== null && $lastOut !== null && $lastOut <= $r['first']) {
                $lastOut = null;   // logout belongs to a session started on a previous day
            }
            $mins = '';
            if ($r['first'] !== null && $lastOut !== null) {
                $mins = (int)round((strtotime($lastOut) - strtotime($r['first'])) / 60);
            }
            fputcsv($fp, [$r['date'], $r['staff'], $r['first'] ?? '', $lastOut ?? '', $mins]);
        }
    } elseif ($exportType === 'full') {
        fputcsv($fp, ['Timestamp', 'Staff', 'Action', 'Entity Type', 'Entity ID', 'Details']);
        foreach (array_reverse($entries) as $e) {
            fputcsv($fp, [$e['timestamp'], $e['staff_name'], $e['action'], $e['entity_type'], $e['entity_id'], $e['details']]);
        }
    }
    fclose($fp);
    exit;
}

// ---- Filter ----
$filterStaff     = trim($_GET['staff'] ?? '');
$filterDate      = trim($_GET['date'] ?? '');
$filterDateFrom  = trim($_GET['from'] ?? '');
$filterDateTo    = trim($_GET['to'] ?? '');
$filterAction    = trim($_GET['action'] ?? '');
$activeTab       = trim($_GET['tab'] ?? 'overview');
if (!in_array($activeTab, ['overview', 'attendance', 'ingestion', 'activity'], true)) {
    $activeTab = 'overview';
}

// Filter out login/logout for ingestion reports
$ingestionActions = ['create_passenger', 'create_driver'];
$ingestions = array_filter($entries, fn($e) => in_array($e['action'], $ingestionActions, true));

// Apply date range filter to ingestions
if ($filterDateFrom !== '') {
    $ingestions = array_filter($ingestions, fn($e) => substr($e['timestamp'], 0, 10) >= $filterDateFrom);
}
if ($filterDateTo !== '') {
    $ingestions = array_filter($ingestions, fn($e) => substr($e['timestamp'], 0, 10) <= $filterDateTo);
}
if ($filterStaff !== '') {
    $ingestions = array_filter($ingestions, fn($e) => $e['staff_name'] === $filterStaff);
}
if ($filterDate !== '') {
    $ingestions = array_filter($ingestions, fn($e) => str_starts_with($e['timestamp'], $filterDate));
}

// ---- Stats by staff ----
$byStaff = [];
foreach ($ingestions as $e) {
    $name = $e['staff_name'];
    if (!isset($byStaff[$name])) {
        $byStaff[$name] = ['passengers' => 0, 'drivers' => 0, 'total' => 0];
    }
    if ($e['action'] === 'create_passenger') $byStaff[$name]['passengers']++;
    if ($e['action'] === 'create_driver') $byStaff[$name]['drivers']++;
    $byStaff[$name]['total']++;
}
uasort($byStaff, fn($a, $b) => $b['total'] - $a['total']);

// ---- Stats by date ----
$byDate = [];
foreach ($ingestions as $e) {
    $date = substr($e['timestamp'], 0, 10);
    if (!isset($byDate[$date])) {
        $byDate[$date] = ['passengers' => 0, 'drivers' => 0, 'total' => 0];
    }
    if ($e['action'] === 'create_passenger') $byDate[$date]['passengers']++;
    if ($e['action'] === 'create_driver') $byDate[$date]['drivers']++;
    $byDate[$date]['total']++;
}
krsort($byDate);

// ---- Stats by date + staff ----
$byDateStaff = [];
foreach ($ingestions as $e) {
    $date = substr($e['timestamp'], 0, 10);
    $name = $e['staff_name'];
    $key  = $date . '|' . $name;
    if (!isset($byDateStaff[$key])) {
        $byDateStaff[$key] = ['date' => $date, 'staff' => $name, 'passengers' => 0, 'drivers' => 0, 'total' => 0];
    }
    if ($e['action'] === 'create_passenger') $byDateStaff[$key]['passengers']++;
    if ($e['action'] === 'create_driver') $byDateStaff[$key]['drivers']++;
    $byDateStaff[$key]['total']++;
}
krsort($byDateStaff);

// ---- Totals ----
$totalPassengers = array_sum(array_column($byStaff, 'passengers'));
$totalDrivers    = array_sum(array_column($byStaff, 'drivers'));
$totalAll        = array_sum(array_column($byStaff, 'total'));

// ---- Filtered activity log ----
$filteredEntries = $entries;
if ($filterStaff !== '') {
    $filteredEntries = array_filter($filteredEntries, fn($e) => $e['staff_name'] === $filterStaff);
}
if ($filterDate !== '') {
    $filteredEntries = array_filter($filteredEntries, fn($e) => str_starts_with($e['timestamp'], $filterDate));
}
if ($filterDateFrom !== '') {
    $filteredEntries = array_filter($filteredEntries, fn($e) => substr($e['timestamp'], 0, 10) >= $filterDateFrom);
}
if ($filterDateTo !== '') {
    $filteredEntries = array_filter($filteredEntries, fn($e) => substr($e['timestamp'], 0, 10) <= $filterDateTo);
}
if ($filterAction !== '') {
    $filteredEntries = array_filter($filteredEntries, fn($e) => $e['action'] === $filterAction);
}
$filteredEntries = array_values(array_reverse($filteredEntries));

// ---- Time In/Out: one row per date + staff (first login / last logout) ----
$attendance = [];
foreach ($entries as $e) {
    if ($e['action'] !== 'login' && $e['action'] !== 'logout') continue;
    $date = substr($e['timestamp'], 0, 10);
    $key  = $date . '|' . $e['staff_name'];
    if (!isset($attendance[$key])) {
        $attendance[$key] = [
            'staff'       => $e['staff_name'],
            'date'        => $date,
            'first_login' => null,
            'last_logout' => null,
            'last_event'  => null,
            'last_action' => '',
        ];
    }
    if ($e['action'] === 'login' && ($attendance[$key]['first_login'] === null || $e['timestamp'] < $attendance[$key]['first_login'])) {
        $attendance[$key]['first_login'] = $e['timestamp'];
    }
    if ($e['action'] === 'logout' && ($attendance[$key]['last_logout'] === null || $e['timestamp'] > $attendance[$key]['last_logout'])) {
        $attendance[$key]['last_logout'] = $e['timestamp'];
    }
    if ($attendance[$key]['last_event'] === null || $e['timestamp'] > $attendance[$key]['last_event']) {
        $attendance[$key]['last_event']  = $e['timestamp'];
        $attendance[$key]['last_action'] = $e['action'];
    }
}
$timeSessions = array_values($attendance);
// Newest date first, staff A-Z within the same date
usort($timeSessions, fn($a, $b) => [$b['date'], $a['staff']] <=> [$a['date'], $b['staff']]);
// "Active now" only when the day's last event is a login AND that day is today
$today = date('Y-m-d');
foreach ($timeSessions as &$s) {
    $s['is_active'] = ($s['last_action'] === 'login' && $s['date'] === $today);
}
unset($s);
// First in first (chronological order - don't reverse)
// Apply filters to time sessions
if ($filterStaff !== '') {
    $timeSessions = array_filter($timeSessions, fn($s) => $s['staff'] === $filterStaff);
    $timeSessions = array_values($timeSessions);
}
if ($filterDate !== '') {
    $timeSessions = array_filter($timeSessions, fn($s) => $s['date'] === $filterDate);
    $timeSessions = array_values($timeSessions);
}
if ($filterDateFrom !== '') {
    $timeSessions = array_filter($timeSessions, fn($s) => $s['date'] >= $filterDateFrom);
    $timeSessions = array_values($timeSessions);
}
if ($filterDateTo !== '') {
    $timeSessions = array_filter($timeSessions, fn($s) => $s['date'] <= $filterDateTo);
    $timeSessions = array_values($timeSessions);
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Staff Reports</h4>
  <div class="dropdown">
    <button class="btn btn-sm btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
      Export CSV
    </button>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item" href="?export=ingestion">Ingestion Summary (per staff)</a></li>
      <li><a class="dropdown-item" href="?export=daily">Daily Breakdown (date + staff)</a></li>
      <li><a class="dropdown-item" href="?export=attendance">Attendance (time in/out)</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="?export=full">Full Activity Log</a></li>
    </ul>
  </div>
</div>

<!-- Date Range Filter -->
<div class="pr-card">
  <div class="pr-card-title">Filter Reports</div>
  <form method="get">
    <div class="pr-filter-row">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
      <div class="pr-filter-field">
        <label>From</label>
        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($filterDateFrom) ?>">
      </div>
      <div class="pr-filter-field">
        <label>To</label>
        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($filterDateTo) ?>">
      </div>
      <div class="pr-filter-field">
        <label>Staff</label>
        <select name="staff" class="form-control">
          <option value="">All Staff</option>
          <?php foreach (STAFF_LIST as $name): ?>
            <option value="<?= htmlspecialchars($name) ?>" <?= $filterStaff === $name ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pr-filter-actions">
        <button type="submit" class="btn btn-pr-primary">Filter</button>
        <a href="staff_reports.php" class="btn btn-pr-secondary">Clear</a>
      </div>
      <?php if ($filterStaff || $filterDate || $filterDateFrom || $filterDateTo): ?>
        <span class="badge bg-info align-self-center">Filtered</span>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Report Tabs -->
<ul class="nav nav-pills mb-3" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $activeTab === 'overview' ? 'active' : '' ?>" id="tab-overview-btn" data-bs-toggle="pill" data-bs-target="#tab-overview" type="button" role="tab" aria-controls="tab-overview" aria-selected="<?= $activeTab === 'overview' ? 'true' : 'false' ?>">Overview</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $activeTab === 'attendance' ? 'active' : '' ?>" id="tab-attendance-btn" data-bs-toggle="pill" data-bs-target="#tab-attendance" type="button" role="tab" aria-controls="tab-attendance" aria-selected="<?= $activeTab === 'attendance' ? 'true' : 'false' ?>">Staff In / Out</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $activeTab === 'ingestion' ? 'active' : '' ?>" id="tab-ingestion-btn" data-bs-toggle="pill" data-bs-target="#tab-ingestion" type="button" role="tab" aria-controls="tab-ingestion" aria-selected="<?= $activeTab === 'ingestion' ? 'true' : 'false' ?>">Ingestion Stats</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $activeTab === 'activity' ? 'active' : '' ?>" id="tab-activity-btn" data-bs-toggle="pill" data-bs-target="#tab-activity" type="button" role="tab" aria-controls="tab-activity" aria-selected="<?= $activeTab === 'activity' ? 'true' : 'false' ?>">Activity Log</button>
  </li>
</ul>

<div class="tab-content">
<div class="tab-pane fade <?= $activeTab === 'overview' ? 'show active' : '' ?>" id="tab-overview" role="tabpanel" aria-labelledby="tab-overview-btn">
<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body">
        <h2 class="text-primary mb-0"><?= $totalAll ?></h2>
        <small class="text-muted">Total Ingested</small>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body">
        <h2 class="text-success mb-0"><?= $totalPassengers ?></h2>
        <small class="text-muted">Passengers</small>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body">
        <h2 class="text-info mb-0"><?= $totalDrivers ?></h2>
        <small class="text-muted">Drivers</small>
      </div>
    </div>
  </div>
</div>

</div><!-- /tab-pane: overview -->

<!-- Staff In / Out tab -->
<div class="tab-pane fade <?= $activeTab === 'attendance' ? 'show active' : '' ?>" id="tab-attendance" role="tabpanel" aria-labelledby="tab-attendance-btn">
<!-- Staff Time In/Out -->
<div class="card mb-4">
  <div class="card-header bg-white fw-semibold">Staff Time In / Out</div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0 pr-table">
      <thead>
        <tr><th>Date</th><th>Staff</th><th>Time In</th><th>Time Out</th><th>Duration</th></tr>
      </thead>
      <tbody>
        <?php if (empty($timeSessions)): ?>
          <tr><td colspan="5" class="text-muted text-center py-3">No sessions recorded yet.</td></tr>
        <?php else: ?>
          <?php foreach (array_slice($timeSessions, 0, 100) as $s): ?>
            <?php
              // A logout that happened BEFORE the day's first login belongs to a
              // session started on a previous day - ignore it for this row.
              $lastOut = $s['last_logout'];
              if ($s['first_login'] !== null && $lastOut !== null && $lastOut <= $s['first_login']) {
                  $lastOut = null;
              }

              if ($s['first_login'] !== null && $lastOut !== null) {
                  $diff = strtotime($lastOut) - strtotime($s['first_login']);
                  $hours = floor($diff / 3600);
                  $mins  = floor(($diff % 3600) / 60);
                  $duration = ($hours > 0 ? "{$hours}h " : '') . "{$mins}m";
              } elseif ($s['is_active']) {
                  $duration = '<span class="badge bg-success">Active now</span>';
              } else {
                  $duration = '<span class="text-muted">--</span>';
              }

              $timeInDisplay  = $s['first_login'] !== null ? htmlspecialchars(substr($s['first_login'], 11)) : '<span class="text-muted">--</span>';
              $timeOutDisplay = $lastOut !== null ? htmlspecialchars(substr($lastOut, 11)) : '<span class="text-muted">--</span>';
            ?>
            <tr>
              <td><?= htmlspecialchars($s['date']) ?></td>
              <td><?= htmlspecialchars($s['staff']) ?></td>
              <td><?= $timeInDisplay ?></td>
              <td><?= $timeOutDisplay ?></td>
              <td><?= $duration ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /tab-pane: attendance -->

<!-- Ingestion Stats tab -->
<div class="tab-pane fade <?= $activeTab === 'ingestion' ? 'show active' : '' ?>" id="tab-ingestion" role="tabpanel" aria-labelledby="tab-ingestion-btn">
<!-- Per Staff Table -->
<div class="card mb-4">
  <div class="card-header bg-white fw-semibold">Ingested Per Staff</div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0 pr-table">
      <thead>
        <tr><th>Staff</th><th class="text-center">Passengers</th><th class="text-center">Drivers</th><th class="text-center">Total</th></tr>
      </thead>
      <tbody>
        <?php if (empty($byStaff)): ?>
          <tr><td colspan="4" class="text-muted text-center py-3">No ingestion activity yet.</td></tr>
        <?php else: ?>
          <?php foreach ($byStaff as $name => $counts): ?>
            <tr>
              <td><a href="?staff=<?= urlencode($name) ?>"><?= htmlspecialchars($name) ?></a></td>
              <td class="text-center"><?= $counts['passengers'] ?></td>
              <td class="text-center"><?= $counts['drivers'] ?></td>
              <td class="text-center fw-bold"><?= $counts['total'] ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="table-dark">
            <td class="fw-bold">TOTAL</td>
            <td class="text-center fw-bold"><?= $totalPassengers ?></td>
            <td class="text-center fw-bold"><?= $totalDrivers ?></td>
            <td class="text-center fw-bold"><?= $totalAll ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Daily Breakdown (Date + Staff) -->
<div class="card mb-4">
  <div class="card-header bg-white fw-semibold">Daily Breakdown (Date + Staff)</div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0 pr-table">
      <thead>
        <tr><th>Date</th><th>Staff</th><th class="text-center">Passengers</th><th class="text-center">Drivers</th><th class="text-center">Total</th></tr>
      </thead>
      <tbody>
        <?php if (empty($byDateStaff)): ?>
          <tr><td colspan="5" class="text-muted text-center py-3">No ingestion activity yet.</td></tr>
        <?php else: ?>
          <?php foreach (array_slice($byDateStaff, 0, 60, true) as $row): ?>
            <tr>
              <td><a href="?date=<?= urlencode($row['date']) ?>"><?= htmlspecialchars($row['date']) ?></a></td>
              <td><a href="?staff=<?= urlencode($row['staff']) ?>"><?= htmlspecialchars($row['staff']) ?></a></td>
              <td class="text-center"><?= $row['passengers'] ?></td>
              <td class="text-center"><?= $row['drivers'] ?></td>
              <td class="text-center fw-bold"><?= $row['total'] ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- By Date Table -->
<div class="card mb-4">
  <div class="card-header bg-white fw-semibold">Activity By Date (Totals)</div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0 pr-table">
      <thead>
        <tr><th>Date</th><th class="text-center">Passengers</th><th class="text-center">Drivers</th><th class="text-center">Total</th></tr>
      </thead>
      <tbody>
        <?php if (empty($byDate)): ?>
          <tr><td colspan="4" class="text-muted text-center py-3">No activity yet.</td></tr>
        <?php else: ?>
          <?php foreach (array_slice($byDate, 0, 30, true) as $date => $counts): ?>
            <tr>
              <td><a href="?date=<?= urlencode($date) ?>"><?= htmlspecialchars($date) ?></a></td>
              <td class="text-center"><?= $counts['passengers'] ?></td>
              <td class="text-center"><?= $counts['drivers'] ?></td>
              <td class="text-center fw-bold"><?= $counts['total'] ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="table-dark">
            <td class="fw-bold">TOTAL</td>
            <td class="text-center fw-bold"><?= $totalPassengers ?></td>
            <td class="text-center fw-bold"><?= $totalDrivers ?></td>
            <td class="text-center fw-bold"><?= $totalAll ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /tab-pane: ingestion -->

<!-- Activity Log tab -->
<div class="tab-pane fade <?= $activeTab === 'activity' ? 'show active' : '' ?>" id="tab-activity" role="tabpanel" aria-labelledby="tab-activity-btn">
<!-- Activity Log (filtered) -->
<div class="card mb-4">
  <div class="card-header bg-white">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <span class="fw-semibold">
        Activity Log
        <?php if ($filterStaff): ?><span class="badge bg-primary"><?= htmlspecialchars($filterStaff) ?></span><?php endif; ?>
        <?php if ($filterDate): ?><span class="badge bg-secondary"><?= htmlspecialchars($filterDate) ?></span><?php endif; ?>
        <?php if ($filterDateFrom || $filterDateTo): ?><span class="badge bg-secondary"><?= htmlspecialchars($filterDateFrom ?: '...') ?> to <?= htmlspecialchars($filterDateTo ?: '...') ?></span><?php endif; ?>
        <?php if ($filterAction): ?><span class="badge bg-info"><?= htmlspecialchars($filterAction) ?></span><?php endif; ?>
      </span>
      <?php if ($filterStaff || $filterDate || $filterDateFrom || $filterDateTo || $filterAction): ?>
        <a href="staff_reports.php?tab=activity" class="btn btn-sm btn-pr-secondary">Clear All</a>
      <?php endif; ?>
    </div>
    <?php
      // Build base query string without action param
      $baseParams = array_filter([
          'staff' => $filterStaff,
          'from'  => $filterDateFrom,
          'to'    => $filterDateTo,
          'date'  => $filterDate,
      ]);
      $baseParams['tab'] = 'activity';   // action filters live in the Activity Log tab
      $baseQuery = http_build_query($baseParams);
      $baseUrl   = 'staff_reports.php' . ($baseQuery ? "?$baseQuery&" : '?');
    ?>
    <ul class="nav nav-tabs card-header-tabs" role="tablist">
      <li class="nav-item"><a class="nav-link <?= $filterAction === '' ? 'active' : '' ?>" href="staff_reports.php?<?= $baseQuery ?>">All</a></li>
      <li class="nav-item"><a class="nav-link <?= $filterAction === 'login' ? 'active' : '' ?>" href="<?= $baseUrl ?>action=login">Login</a></li>
      <li class="nav-item"><a class="nav-link <?= $filterAction === 'logout' ? 'active' : '' ?>" href="<?= $baseUrl ?>action=logout">Logout</a></li>
      <li class="nav-item"><a class="nav-link <?= $filterAction === 'create_passenger' ? 'active' : '' ?>" href="<?= $baseUrl ?>action=create_passenger">Create Passenger</a></li>
      <li class="nav-item"><a class="nav-link <?= $filterAction === 'create_driver' ? 'active' : '' ?>" href="<?= $baseUrl ?>action=create_driver">Create Driver</a></li>
    </ul>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 pr-table">
      <thead>
        <tr><th>Time</th><th>Staff</th><th>Action</th><th>Type</th><th>ID</th><th>Details</th></tr>
      </thead>
      <tbody>
        <?php if (empty($filteredEntries)): ?>
          <tr><td colspan="6" class="text-muted text-center py-3">No activity found.</td></tr>
        <?php else: ?>
          <?php foreach (array_slice($filteredEntries, 0, 200) as $e): ?>
            <tr>
              <td class="text-nowrap small"><?= htmlspecialchars($e['timestamp']) ?></td>
              <td><?= htmlspecialchars($e['staff_name']) ?></td>
              <td>
                <?php
                  $badge = match($e['action']) {
                      'create_passenger' => 'bg-success',
                      'create_driver' => 'bg-info',
                      'login' => 'bg-secondary',
                      'logout' => 'bg-dark',
                      default => 'bg-warning text-dark',
                  };
                ?>
                <span class="badge <?= $badge ?>"><?= htmlspecialchars($e['action']) ?></span>
              </td>
              <td><?= htmlspecialchars($e['entity_type']) ?></td>
              <td><?= htmlspecialchars($e['entity_id']) ?></td>
              <td class="text-truncate" style="max-width:220px" title="<?= htmlspecialchars($e['details']) ?>"><?= htmlspecialchars($e['details']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php if (count($filteredEntries) > 200): ?>
      <div class="text-muted small text-center py-2">Showing first 200 of <?= count($filteredEntries) ?> entries. Export CSV for full data.</div>
    <?php endif; ?>
  </div>
</div><!-- /tab-pane: activity -->
</div><!-- /tab-content -->

<?php require __DIR__ . '/includes/footer.php'; ?>
