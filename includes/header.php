<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($tabTitle) ? $tabTitle . ' - PinoyRide Admin Portal' : 'PinoyRide Admin Portal' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/cosmo/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<style>
  body { background: #f4f6f9; }
  .navbar-brand { font-weight: 600; }
  .header-yellow { background-color: #FFF9C4; }
  .header-yellow .navbar-brand { color: #212529; }
  .brand-logo { height: 42px; width: auto; background: #fff; border-radius: .4rem; padding: 3px; }
  .filter-card { border: none; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
  .table thead th { white-space: nowrap; background: #eef1f5; }
  .badge-status-1 { background-color: #198754; }
  .badge-status-0 { background-color: #6c757d; }
  .table-responsive { border-radius: .5rem; overflow-x: auto; }
</style>
</head>
<body>
<nav class="navbar navbar-light header-yellow mb-4">
  <div class="container px-4">
    <span class="navbar-brand d-flex align-items-center gap-2">
      <img src="assets/logo.jpg" alt="Pinoy Ride Transport Corporation" class="brand-logo">
      <span>PinoyRide Admin Portal</span>
    </span>
    <div class="d-flex gap-2 align-items-center">
      <a href="index.php" class="btn btn-sm <?= $activeNav === 'customers' ? 'btn-dark' : 'btn-outline-dark' ?>">Customers</a>
      <a href="customer_create.php" class="btn btn-sm <?= $activeNav === 'add_passenger' ? 'btn-dark' : 'btn-outline-dark' ?>">+ Add Passengers</a>
      <a href="rider_create.php" class="btn btn-sm <?= $activeNav === 'add_driver' ? 'btn-dark' : 'btn-outline-dark' ?>">+ Add Drivers</a>
      <a href="riders.php" class="btn btn-sm <?= $activeNav === 'riders' ? 'btn-dark' : 'btn-outline-dark' ?>">Drivers</a>
      <a href="bookings.php" class="btn btn-sm <?= $activeNav === 'bookings' ? 'btn-dark' : 'btn-outline-dark' ?>">Bookings</a>
      <a href="nearby_drivers.php" class="btn btn-sm <?= $activeNav === 'nearby_drivers' ? 'btn-dark' : 'btn-outline-dark' ?>">Nearby Drivers</a>
      <a href="bulk_search.php" class="btn btn-sm <?= $activeNav === 'bulk_search' ? 'btn-dark' : 'btn-outline-dark' ?>">Bulk Search</a>
      <a href="commission.php" class="btn btn-sm <?= $activeNav === 'commission' ? 'btn-dark' : 'btn-outline-dark' ?>">Commission</a>
      <a href="staff_reports.php" class="btn btn-sm <?= $activeNav === 'reports' ? 'btn-dark' : 'btn-outline-dark' ?>">Reports</a>
      <span class="text-secondary mx-1">|</span>
      <span class="text-dark small"><?= htmlspecialchars(current_staff()) ?></span>
      <a href="staff_select.php" class="btn btn-sm btn-outline-dark" title="Switch staff">Switch</a>
      <a href="shutdown.php" class="btn btn-sm btn-outline-danger">Shutdown</a>
    </div>
  </div>
</nav>
<div class="container px-4">
