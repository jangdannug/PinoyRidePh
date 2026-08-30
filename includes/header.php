<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PinoyRide Admin Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/cosmo/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/admin-theme.css">
<link rel="icon" type="image/png" href="assets/logo.png">
<style>
  .table thead th { white-space: nowrap; background: #eef1f5; }
  .badge-status-1 { background-color: #198754; }
  .badge-status-0 { background-color: #6c757d; }
  .badge-status-1, .badge-status-0 { border-radius: 24px; padding: 4px 12px; font-weight: 600; }
  .table-responsive { border-radius: .5rem; overflow-x: auto; }
</style>
</head>
<body>
<nav class="pr-navbar">
  <div class="pr-logo">
    <img src="assets/logo.png" alt="Pinoy Ride Transport Corporation">
    Pinoy<span class="pr-brand-ride">Ride</span>
    <span class="pr-brand-suffix">Admin Portal</span>
  </div>

  <div class="pr-nav-links">
    <a href="index.php" class="<?= ($activeNav ?? '') === 'customers' ? 'active' : '' ?>">Customers</a>
    <div class="dropdown">
      <a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" class="<?= ($activeNav ?? '') === 'add_passenger' || ($activeNav ?? '') === 'add_driver' ? 'active' : '' ?>">+ Add New</a>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="customer_create.php">Passenger</a></li>
        <li><a class="dropdown-item" href="rider_create.php">Driver</a></li>
      </ul>
    </div>
    <a href="riders.php" class="<?= ($activeNav ?? '') === 'riders' ? 'active' : '' ?>">Drivers</a>
    <a href="bookings.php" class="<?= ($activeNav ?? '') === 'bookings' ? 'active' : '' ?>">Bookings</a>
    <a href="nearby_drivers.php" class="<?= ($activeNav ?? '') === 'nearby_drivers' ? 'active' : '' ?>">Nearby Drivers</a>
    <a href="bulk_search.php" class="<?= ($activeNav ?? '') === 'bulk_search' ? 'active' : '' ?>">Bulk Search</a>
    <a href="commission.php" class="<?= ($activeNav ?? '') === 'commission' ? 'active' : '' ?>">Commission</a>
    <a href="staff_reports.php" class="<?= ($activeNav ?? '') === 'reports' ? 'active' : '' ?>">Reports</a>
  </div>

  <div class="pr-nav-user">
    <div class="dropdown">
      <a class="pr-user-menu dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Signed in as <?= htmlspecialchars(current_staff()) ?>">
        <span class="user-dot"></span><?= htmlspecialchars(current_staff()) ?>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header mb-1">Signed in as</h6></li>
        <li><span class="dropdown-item-text fw-semibold"><?= htmlspecialchars(current_staff()) ?></span></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="staff_select.php">Switch staff&hellip;</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="shutdown.php">Shut down panel&hellip;</a></li>
      </ul>
    </div>
  </div>
</nav>
<div class="container px-4 py-4">
