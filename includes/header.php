<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $tabTitle  ?? 'Pinoy Ride'?></title>
<link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/cosmo/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
  body { background: #f4f6f9; }
  .navbar-brand { font-weight: 600; }
  .filter-card { border: none; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
  .table thead th { white-space: nowrap; background: #eef1f5; }
  .badge-status-1 { background-color: #198754; }
  .badge-status-0 { background-color: #6c757d; }
  .table-responsive { border-radius: .5rem; overflow-x: auto; }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container px-4">
    <span class="navbar-brand">RiderApp Admin</span>
    <div class="d-flex gap-2 align-items-center">
      <a href="index.php" class="btn btn-sm <?= $activeNav === 'customers' ? 'btn-light' : 'btn-outline-light' ?>">Customers</a>
      <a href="customer_create.php" class="btn btn-sm <?= $activeNav === 'add_passenger' ? 'btn-light' : 'btn-outline-light' ?>">+ Add Passengers</a>
      <a href="rider_create.php" class="btn btn-sm <?= $activeNav === 'add_driver' ? 'btn-light' : 'btn-outline-light' ?>">+ Add Drivers</a>
      <a href="riders.php" class="btn btn-sm <?= $activeNav === 'riders' ? 'btn-light' : 'btn-outline-light' ?>">Drivers</a>
      <a href="bookings.php" class="btn btn-sm <?= $activeNav === 'bookings' ? 'btn-light' : 'btn-outline-light' ?>">Bookings</a>
      <a href="nearby_drivers.php" class="btn btn-sm <?= $activeNav === 'nearby_drivers' ? 'btn-light' : 'btn-outline-light' ?>">Nearby Drivers</a>
      <a href="bulk_search.php" class="btn btn-sm <?= $activeNav === 'bulk_search' ? 'btn-light' : 'btn-outline-light' ?>">Bulk Search</a>
      <a href="commission.php" class="btn btn-sm <?= $activeNav === 'commission' ? 'btn-light' : 'btn-outline-light' ?>">Commission</a>
      <a href="staff_reports.php" class="btn btn-sm <?= $activeNav === 'reports' ? 'btn-light' : 'btn-outline-light' ?>">Reports</a>
      <span class="text-secondary mx-1">|</span>
      <span class="text-light small"><?= htmlspecialchars(current_staff()) ?></span>
      <a href="staff_select.php" class="btn btn-sm btn-outline-light" title="Switch staff">Switch</a>
      <a href="shutdown.php" class="btn btn-sm btn-outline-danger">Shutdown</a>
    </div>
  </div>
</nav>
<div class="container px-4">
