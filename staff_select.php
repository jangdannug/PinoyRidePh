<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/activity_log.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_name'])) {
    $name = trim($_POST['staff_name']);
    if (in_array($name, STAFF_LIST, true)) {
        // Log logout for previous staff if switching
        if (current_staff() !== '' && current_staff() !== $name) {
            log_activity('logout', '', '', 'Switched to ' . $name);
        }
        set_current_staff($name);
        log_activity('login', '', '', 'Staff logged in');
        header('Location: index.php');
        exit;
    }
}

$tabTitle = 'Select Staff';

// Sort staff list: last logged-in first
$entries = read_activity_log();
$lastLogin = [];
foreach ($entries as $e) {
    if ($e['action'] === 'login') {
        $lastLogin[$e['staff_name']] = $e['timestamp'];
    }
}
arsort($lastLogin); // most recent first

// Build ordered list: recently active staff first, then the rest
$recentStaff = array_keys($lastLogin);
$remainingStaff = array_diff(STAFF_LIST, $recentStaff);
$orderedStaff = array_merge($recentStaff, $remainingStaff);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Select Staff — PinoyRide Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/cosmo/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f4f6f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .staff-card { width: 100%; max-width: 400px; }
  .staff-list { max-height: 300px; overflow-y: auto; }
  .staff-item { cursor: pointer; transition: background 0.1s; }
  .staff-item:hover { background: #e3f2fd; }
  .staff-item.active { background: #1976d2; color: #fff; }
</style>
</head>
<body>

<div class="staff-card">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="text-center mb-3">PinoyRide Admin</h4>
      <p class="text-muted text-center mb-3">Select your name to continue</p>

      <input type="text" id="search" class="form-control mb-3" placeholder="Search name..." autofocus>

      <form method="post" id="staff-form">
        <input type="hidden" name="staff_name" id="selected-staff" value="">
        <div class="staff-list list-group mb-3" id="staff-list">
          <?php foreach ($orderedStaff as $name): ?>
            <button type="button" class="list-group-item list-group-item-action staff-item" data-name="<?= htmlspecialchars($name) ?>">
              <?= htmlspecialchars($name) ?>
              <?php if (isset($lastLogin[$name])): ?>
                <small class="text-muted float-end"><?= htmlspecialchars(substr($lastLogin[$name], 0, 16)) ?></small>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary w-100" id="continue-btn" disabled>Continue</button>
      </form>
    </div>
  </div>
</div>

<script>
const search = document.getElementById('search');
const items = document.querySelectorAll('.staff-item');
const selectedInput = document.getElementById('selected-staff');
const continueBtn = document.getElementById('continue-btn');

// Search filter
search.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    items.forEach(item => {
        item.style.display = item.dataset.name.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Select staff
items.forEach(item => {
    item.addEventListener('click', function() {
        items.forEach(i => i.classList.remove('active'));
        this.classList.add('active');
        selectedInput.value = this.dataset.name;
        continueBtn.disabled = false;
    });
});
</script>

</body>
</html>
