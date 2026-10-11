<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_status.php';

$tabTitle = 'Whose Booking';

// ---------------------------------------------------------------
// Live view of who is booking *right now* — every booking still in
// progress (status 0/1/2: Looking for Driver / Accepted / In Transit).
// Completed (3) and cancelled (4) trips are intentionally excluded;
// see includes/booking_status.php for the status meanings.
//
// Rows are ordered status ASC, created_at ASC so the longest-waiting
// "Looking for Driver" requests sit at the top of the queue, the way
// dispatch staff actually work the list.
// ---------------------------------------------------------------

$errorMsg   = '';
$bookings   = [];
$counts     = [0 => 0, 1 => 0, 2 => 0];
$totalActive = 0;
$lastUpdated = date('H:i:s');

// Tab selection: ?status=0|1|2 opens that status tab, anything else = All.
// Strict string check so junk like ?status=abc can't coerce into status 0.
$TAB_STATUSES = [0, 1, 2];
$statusParam  = $_GET['status'] ?? null;
$statusFilter = (is_string($statusParam) && in_array($statusParam, ['0', '1', '2'], true))
    ? (int)$statusParam
    : null;

try {
    $pdo = get_pdo();

    // Per-status counters for the tab badges (always all three tabs' counts,
    // regardless of which tab is currently selected)
    $countStmt = $pdo->query(
        'SELECT status, COUNT(*) AS cnt
         FROM public.booking
         WHERE status IN (0, 1, 2)
         GROUP BY status'
    );
    foreach ($countStmt->fetchAll() as $row) {
        $counts[(int)$row['status']] = (int)$row['cnt'];
    }
    $totalActive = array_sum($counts);

    // Who is currently booking — passenger identity comes from the
    // customer table, driver identity from riders (same joins as bookings.php).
    // Narrowed to a single status when a status tab is selected.
    $sql = "SELECT
            b.id, b.ref_code, b.pickup_location, b.dropoff_location,
            b.status, b.payment_type, b.booking_type,
            b.date_created, b.time_created, b.created_at,
            c.fname       AS customer_fname,
            c.lname       AS customer_lname,
            c.mobile      AS customer_mobile,
            r.first_name  AS rider_fname,
            r.last_name   AS rider_lname,
            r.mobile_no   AS rider_mobile
         FROM public.booking b
         LEFT JOIN public.customer c ON c.id = b.customer_id
         LEFT JOIN public.riders   r ON r.id = b.rider_id
         WHERE b.status IN (0, 1, 2)";
    if ($statusFilter !== null) {
        $sql .= ' AND b.status = :tab_status';
    }
    $sql .= ' ORDER BY b.status ASC, b.created_at ASC';

    $stmt = $pdo->prepare($sql);
    if ($statusFilter !== null) {
        $stmt->bindValue(':tab_status', $statusFilter, PDO::PARAM_INT);
    }
    $stmt->execute();
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) {
    $errorMsg = 'Query failed: ' . $e->getMessage();
}

// Human-readable time since the booking was created (e.g. "12m 5s")
function elapsed_label(?string $createdAt): string
{
    if ($createdAt === null || $createdAt === '') {
        return '—';
    }
    $ts = strtotime($createdAt);
    if ($ts === false) {
        return '—';
    }
    $secs = max(0, time() - $ts);
    $h    = intdiv($secs, 3600);
    $m    = intdiv($secs % 3600, 60);
    if ($h > 0) {
        return $h . 'h ' . $m . 'm';
    }
    if ($m > 0) {
        return $m . 'm ' . ($secs % 60) . 's';
    }
    return $secs . 's';
}

$activeNav = 'whose_booking';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-1 fw-bold">Whose Booking</h5>
    <div class="text-muted small">Who is booking right now — use the tabs to filter by trip status.</div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="text-muted small">Updated <?= htmlspecialchars($lastUpdated) ?></span>
    <button type="button" class="btn btn-sm btn-pr-secondary" id="refresh-now">Refresh</button>
    <div class="form-check form-switch mb-0">
      <input class="form-check-input" type="checkbox" id="auto-refresh-toggle" checked>
      <label class="form-check-label small text-muted" for="auto-refresh-toggle">Auto-refresh (<span id="refresh-status">on</span>)</label>
    </div>
  </div>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
      <a class="nav-link <?= $statusFilter === null ? 'active' : '' ?>" href="whose_booking.php">
        All <span class="badge bg-dark align-middle"><?= number_format($totalActive) ?></span>
      </a>
    </li>
    <?php foreach ($TAB_STATUSES as $s): ?>
      <li class="nav-item">
        <a class="nav-link <?= $statusFilter === $s ? 'active' : '' ?>" href="whose_booking.php?status=<?= $s ?>">
          <?= htmlspecialchars(BOOKING_STATUSES[$s]) ?>
          <span class="badge <?= booking_status_badge_class($s) ?> align-middle"><?= number_format($counts[$s]) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="pr-table-wrap">
  <div class="pr-table-toolbar">
    <div class="pr-table-toolbar-left">
      <?php $shown = count($bookings); ?>
      <div class="pr-results-count"><?php
        if ($statusFilter === 0) {
            echo number_format($shown) . ' looking for a driver';
        } elseif ($statusFilter === 1) {
            echo number_format($shown) . ' accepted booking' . ($shown === 1 ? '' : 's');
        } elseif ($statusFilter === 2) {
            echo number_format($shown) . ' trip' . ($shown === 1 ? '' : 's') . ' in transit';
        } else {
            echo number_format($shown) . ' active booking' . ($shown === 1 ? '' : 's');
        }
      ?></div>
      <div class="pr-table-search-slot"></div>
    </div>
    <div class="pr-table-toolbar-right">
      <a href="bookings.php" class="btn btn-sm btn-pr-secondary">All Bookings</a>
    </div>
  </div>
  <div class="pr-table-scroll">
  <div class="table-responsive bg-white">
  <table class="table table-striped table-hover align-middle mb-0 dataTable pr-table" data-paging="false">
    <thead>
      <tr>
        <th>Ref Code</th>
        <th>Passenger</th>
        <th>Pickup</th>
        <th>Dropoff</th>
        <th>Driver</th>
        <th>Type</th>
        <th>Payment</th>
        <th>Status</th>
        <th>Booked At</th>
        <th>Elapsed</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($bookings)): ?>
        <tr>
          <td colspan="11" class="text-center text-muted py-4"><?php
            if ($statusFilter === 0) {
                echo 'No passengers are waiting for a driver right now.';
            } elseif ($statusFilter === 1) {
                echo 'No bookings have been accepted by a driver right now.';
            } elseif ($statusFilter === 2) {
                echo 'No trips are in transit right now.';
            } else {
                echo 'No one is booking right now.';
            }
          ?></td>
        </tr>
      <?php else: ?>
        <?php foreach ($bookings as $b): ?>
          <tr>
            <td><?= htmlspecialchars($b['ref_code']) ?></td>
            <td>
              <?php if ($b['customer_fname']): ?>
                <?= htmlspecialchars(trim($b['customer_fname'] . ' ' . $b['customer_lname'])) ?>
                <div class="text-muted small"><?= htmlspecialchars($b['customer_mobile'] ?? '') ?></div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="pr-address-cell" title="<?= htmlspecialchars($b['pickup_location'] ?? '') ?>"><?= htmlspecialchars($b['pickup_location'] ?? '—') ?></td>
            <td class="pr-address-cell" title="<?= htmlspecialchars($b['dropoff_location'] ?? '') ?>"><?= htmlspecialchars($b['dropoff_location'] ?? '—') ?></td>
            <td>
              <?php if ($b['rider_fname']): ?>
                <?= htmlspecialchars(trim($b['rider_fname'] . ' ' . $b['rider_lname'])) ?>
                <div class="text-muted small"><?= htmlspecialchars($b['rider_mobile'] ?? '') ?></div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(booking_type_label($b['booking_type'])) ?></td>
            <td><?= htmlspecialchars($b['payment_type'] ?? '—') ?></td>
            <td><span class="badge pr-badge <?= booking_status_badge_class($b['status']) ?>"><?= htmlspecialchars(booking_status_label($b['status'])) ?></span></td>
            <td>
              <?= htmlspecialchars($b['date_created'] ?? '—') ?>
              <div class="text-muted small"><?= htmlspecialchars($b['time_created'] ?? '') ?></div>
            </td>
            <td><?= htmlspecialchars(elapsed_label($b['created_at'])) ?></td>
            <td class="text-nowrap">
              <a href="booking_show.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
              <?php if ($b['rider_fname']): ?>
                <a href="booking_live.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-success">Track</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
  </div>
  </div>

<?php endif; ?>


<script>
// Lightweight auto-refresh so the page really shows who is booking *now*.
// 15-second countdown, pausable via the switch (choice kept for the tab),
// plus a manual Refresh button. Vanilla JS on purpose — it runs before
// jQuery loads in the footer, and it only touches its own controls.
(function () {
  var REFRESH_SECONDS = 15;
  var toggle   = document.getElementById('auto-refresh-toggle');
  var status   = document.getElementById('refresh-status');
  var nowBtn   = document.getElementById('refresh-now');
  if (!toggle || !status || !nowBtn) return;

  var autoOn    = sessionStorage.getItem('whoseBookingAuto') !== '0';
  var remaining = REFRESH_SECONDS;
  var timerId   = null;

  toggle.checked = autoOn;

  function tick() {
    if (timerId) clearTimeout(timerId);
    if (!autoOn) {
      status.textContent = 'paused';
      return;
    }
    if (remaining <= 0) {
      status.textContent = 'refreshing…';
      location.reload();
      return;
    }
    status.textContent = remaining + 's';
    remaining--;
    timerId = setTimeout(tick, 1000);
  }

  toggle.addEventListener('change', function () {
    autoOn = toggle.checked;
    sessionStorage.setItem('whoseBookingAuto', autoOn ? '1' : '0');
    remaining = REFRESH_SECONDS;
    tick();
  });

  nowBtn.addEventListener('click', function () {
    status.textContent = 'refreshing…';
    location.reload();
  });

  tick();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

