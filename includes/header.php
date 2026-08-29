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
  .header-yellow { background-color: #FFF9C4; border-bottom: 1px solid #ece5ae; }
  .header-yellow .navbar-brand { color: #212529; }
  .brand-logo { height: 42px; width: auto; background: #fff; border-radius: .4rem; padding: 3px; }
  .nav-tabsbar { gap: .1rem; }
  .nav-tab-link {
    color: #55523c;
    font-size: .875rem;
    font-weight: 500;
    line-height: 1;
    padding: .5rem .8rem;
    border-radius: 2rem;
    text-decoration: none;
    white-space: nowrap;
  }
  .nav-tab-link:hover { background: rgba(0, 0, 0, .07); color: #212529; }
  .nav-tab-link.active { background: #212529; color: #fff; }
  .nav-user { display: inline-flex; align-items: center; }
  .user-dot { width: 8px; height: 8px; border-radius: 50%; background: #198754; display: inline-block; margin-right: .45rem; }
  .nav-tab-quiet { color: #8a8768; }
  .nav-tab-danger { color: #b02a37; font-weight: 600; }
  .nav-tab-danger:hover { background: rgba(220, 53, 69, .12); color: #b02a37; }
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
    <div class="d-flex align-items-center flex-wrap nav-tabsbar">
      <a href="index.php" class="nav-tab-link <?= $activeNav === 'customers' ? 'active' : '' ?>">Customers</a>
      <div class="dropdown">
        <a class="nav-tab-link dropdown-toggle <?= $activeNav === 'add_passenger' || $activeNav === 'add_driver' ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">+ Add New</a>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item" href="customer_create.php">Passenger</a></li>
          <li><a class="dropdown-item" href="rider_create.php">Driver</a></li>
        </ul>
      </div>
      <a href="riders.php" class="nav-tab-link <?= $activeNav === 'riders' ? 'active' : '' ?>">Drivers</a>
      <a href="bookings.php" class="nav-tab-link <?= $activeNav === 'bookings' ? 'active' : '' ?>">Bookings</a>
      <a href="nearby_drivers.php" class="nav-tab-link <?= $activeNav === 'nearby_drivers' ? 'active' : '' ?>">Nearby Drivers</a>
      <a href="bulk_search.php" class="nav-tab-link <?= $activeNav === 'bulk_search' ? 'active' : '' ?>">Bulk Search</a>
      <a href="commission.php" class="nav-tab-link <?= $activeNav === 'commission' ? 'active' : '' ?>">Commission</a>
      <a href="staff_reports.php" class="nav-tab-link <?= $activeNav === 'reports' ? 'active' : '' ?>">Reports</a>
      <div class="dropdown">
        <a class="nav-tab-link nav-user dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Signed in as <?= htmlspecialchars(current_staff()) ?>">
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
  </div>
</nav>
<div class="container px-4">
