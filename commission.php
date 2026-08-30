<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_status.php';
$tabTitle = 'Commissions';
// ---------------------------------------------------------------
// Read & sanitize filter inputs (GET, so results are bookmarkable)
// ---------------------------------------------------------------
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$status   = trim($_GET['status'] ?? '');
$riderQ   = trim($_GET['rider_q'] ?? '');
$riderId  = (int)($_GET['rider_id'] ?? 0);

// date_created is stored as varchar (assumed 'YYYY-MM-DD'); cast to date for range comparison
$where  = ['b.deleted_at IS NULL'];
$params = [];

if ($dateFrom !== '') {
    $where[] = "TO_DATE(b.date_created, 'YYYY-MM-DD') >= :date_from";
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "TO_DATE(b.date_created, 'YYYY-MM-DD') <= :date_to";
    $params[':date_to'] = $dateTo;
}
if ($status !== '' && ctype_digit($status)) {
    $where[] = 'b.status = :status';
    $params[':status'] = (int)$status;
}
if ($riderId > 0) {
    $where[] = 'b.rider_id = :rider_id';
    $params[':rider_id'] = $riderId;
} elseif ($riderQ !== '') {
    $where[] = "(r.code ILIKE :rider_q OR r.first_name ILIKE :rider_q OR r.last_name ILIKE :rider_q OR r.mobile_no ILIKE :rider_q)";
    $params[':rider_q'] = '%' . $riderQ . '%';
}

$whereSql = implode(' AND ', $where);

$summary      = ['total_bookings' => 0, 'total_commission' => 0, 'total_gross' => 0, 'total_rider_net' => 0, 'total_tip' => 0, 'total_booking_fee' => 0];
$dailyRows    = [];
$topRiders    = [];
$errorMsg     = '';

try {
    $pdo = get_pdo();

    // Only bookings that actually have a payment record carry financial data.
    $fromSql = "FROM public.booking b
                INNER JOIN public.booking_payment bp ON bp.booking_id = b.id
                LEFT JOIN public.riders r ON r.id = b.rider_id
                WHERE {$whereSql}";

    // ---- Summary totals ----
    $summaryStmt = $pdo->prepare(
        "SELECT
            COUNT(*)                          AS total_bookings,
            COALESCE(SUM(bp.commission), 0)   AS total_commission,
            COALESCE(SUM(bp.total_amount), 0) AS total_gross,
            COALESCE(SUM(bp.rider_net_amount), 0) AS total_rider_net,
            COALESCE(SUM(bp.tip), 0)          AS total_tip,
            COALESCE(SUM(bp.booking_fee), 0)  AS total_booking_fee
         {$fromSql}"
    );
    $summaryStmt->execute($params);
    if ($row = $summaryStmt->fetch()) {
        $summary = $row;
    }

    // ---- Daily breakdown ----
    $dailyStmt = $pdo->prepare(
        "SELECT
            b.date_created                    AS d,
            COUNT(*)                          AS bookings,
            COALESCE(SUM(bp.commission), 0)   AS commission,
            COALESCE(SUM(bp.total_amount), 0) AS gross,
            COALESCE(SUM(bp.rider_net_amount), 0) AS rider_net
         {$fromSql}
           AND b.date_created IS NOT NULL AND b.date_created <> ''
         GROUP BY b.date_created
         ORDER BY TO_DATE(b.date_created, 'YYYY-MM-DD') ASC"
    );
    $dailyStmt->execute($params);
    $dailyRows = $dailyStmt->fetchAll();

    // ---- Top riders by commission ----
    $riderStmt = $pdo->prepare(
        "SELECT
            r.id, r.code, r.first_name, r.last_name,
            COUNT(*)                        AS bookings,
            COALESCE(SUM(bp.commission), 0) AS commission
         {$fromSql}
         GROUP BY r.id, r.code, r.first_name, r.last_name
         ORDER BY commission DESC
         LIMIT 10"
    );
    $riderStmt->execute($params);
    $topRiders = $riderStmt->fetchAll();

} catch (PDOException $e) {
    $errorMsg = 'Query failed: ' . $e->getMessage();
}

function fmt_money($v): string
{
    return '₱' . number_format((float)$v, 2);
}

// Helper to keep existing filters when building links
function build_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params), ENT_QUOTES, 'UTF-8');
}

$activeNav = 'commission';
require __DIR__ . '/includes/header.php';
?>

<div class="pr-card">
  <div class="pr-card-title">Filter Commission</div>
  <form method="get">
    <div class="pr-filter-row">
      <div class="pr-filter-field">
        <label>Date Created From</label>
        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="pr-filter-field">
        <label>Date Created To</label>
        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
      </div>
      <div class="pr-filter-field">
        <label>Status</label>
        <select name="status" class="form-control">
          <option value="">-- All --</option>
          <?php foreach (BOOKING_STATUSES as $sVal => $sLabel): ?>
            <option value="<?= $sVal ?>" <?= ((string)$sVal === $status) ? 'selected' : '' ?>><?= htmlspecialchars($sLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pr-filter-field">
        <label>Rider (name / code / mobile)</label>
        <input type="text" name="rider_q" class="form-control" placeholder="Search rider" value="<?= htmlspecialchars($riderQ) ?>" <?= $riderId > 0 ? 'disabled' : '' ?>>
      </div>
      <div class="pr-filter-actions">
        <button type="submit" class="btn btn-pr-primary">Apply</button>
        <a href="commission.php" class="btn btn-pr-secondary">Reset</a>
      </div>
      <?php if ($riderId > 0): ?>
        <input type="hidden" name="rider_id" value="<?= (int)$riderId ?>">
        <div style="flex: 1 1 100%; display: flex; align-items: center; gap: 8px;">
          <span class="text-muted">Scoped to rider #<?= (int)$riderId ?>.</span>
          <a href="commission.php" class="btn btn-sm btn-pr-secondary">Clear rider scope</a>
        </div>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <div class="row g-3 mb-4">
    <div class="col-md-4 col-lg-2">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="text-muted small">Bookings</div>
          <div class="fs-4 fw-semibold"><?= number_format((int)$summary['total_bookings']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-2">
      <div class="card text-center h-100 border-success">
        <div class="card-body">
          <div class="text-muted small">Total Commission</div>
          <div class="fs-4 fw-semibold text-success"><?= fmt_money($summary['total_commission']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-2">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="text-muted small">Gross Fare Total</div>
          <div class="fs-4 fw-semibold"><?= fmt_money($summary['total_gross']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-2">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="text-muted small">Rider Net Total</div>
          <div class="fs-4 fw-semibold"><?= fmt_money($summary['total_rider_net']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-2">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="text-muted small">Avg Commission / Booking</div>
          <div class="fs-4 fw-semibold">
            <?= fmt_money((int)$summary['total_bookings'] > 0 ? $summary['total_commission'] / $summary['total_bookings'] : 0) ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4 col-lg-2">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="text-muted small">Tips + Booking Fees</div>
          <div class="fs-4 fw-semibold"><?= fmt_money($summary['total_tip'] + $summary['total_booking_fee']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Commission by Date</div>
    <div class="card-body">
      <?php if (empty($dailyRows)): ?>
        <span class="text-muted">No data.</span>
      <?php else: ?>
        <canvas id="commissionChart" height="90"></canvas>
      <?php endif; ?>
    </div>
  </div>

  <div class="pr-two-col-grid">
    <div class="pr-card h-100">
      <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
        <span class="fw-bold fw-semibold pr-card-title mb-0">Daily Breakdown</span>
        <span class="text-muted small"><?= number_format(count($dailyRows)) ?> day<?= count($dailyRows) === 1 ? '' : 's' ?></span>
      </div>
                  <div class="pr-table-wrap">
        <div class="pr-table-toolbar">
          <div class="pr-table-toolbar-left">
            <div class="pr-results-count"></div>
            <div class="pr-table-search-slot"></div>
          </div>
          <div class="pr-table-toolbar-right"></div>
        </div>
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0 dataTable pr-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Bookings</th>
                <th>Commission</th>
                <th>Gross Fare</th>
                <th>Rider Net</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($dailyRows)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No data found.</td></tr>
              <?php else: ?>
                <?php foreach (array_reverse($dailyRows) as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars($row['d']) ?></td>
                    <td><?= number_format((int)$row['bookings']) ?></td>
                    <td class="text-success"><?= fmt_money($row['commission']) ?></td>
                    <td><?= fmt_money($row['gross']) ?></td>
                    <td><?= fmt_money($row['rider_net']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <?php if (!empty($dailyRows)): ?>
              <tfoot>
                <tr class="fw-semibold">
                  <td>Total</td>
                  <td><?= number_format((int)$summary['total_bookings']) ?></td>
                  <td class="text-success"><?= fmt_money($summary['total_commission']) ?></td>
                  <td><?= fmt_money($summary['total_gross']) ?></td>
                  <td><?= fmt_money($summary['total_rider_net']) ?></td>
                </tr>
              </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
    </div>

    <div class="pr-card h-100">
      <div class="fw-semibold pr-card-title mb-2">Top Riders by Commission</div>
                            <div class="pr-table-wrap">
        <div class="pr-table-toolbar">
          <div class="pr-table-toolbar-left">
            <div class="pr-results-count"></div>
            <div class="pr-table-search-slot"></div>
          </div>
          <div class="pr-table-toolbar-right"></div>
        </div>
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0 dataTable pr-table">
            <thead>
              <tr>
                <th>Rider</th>
                <th>Bookings</th>
                <th>Commission</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($topRiders)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No data.</td></tr>
              <?php else: ?>
                <?php foreach ($topRiders as $tr): ?>
                  <tr>
                    <td>
                      <?php if ($tr['id']): ?>
                        <a href="rider_show.php?id=<?= (int)$tr['id'] ?>"><?= htmlspecialchars(trim($tr['first_name'] . ' ' . $tr['last_name'])) ?></a>
                        <div class="text-muted small"><?= htmlspecialchars($tr['code'] ?? '') ?></div>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td><?= number_format((int)$tr['bookings']) ?></td>
                    <td class="text-success"><?= fmt_money($tr['commission']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <script>
    <?php if (!empty($dailyRows)): ?>
    new Chart(document.getElementById('commissionChart'), {
      type: 'line',
      data: {
        labels: <?= json_encode(array_map(fn($r) => $r['d'], $dailyRows)) ?>,
        datasets: [{
          label: 'Commission (₱)',
          data: <?= json_encode(array_map(fn($r) => (float)$r['commission'], $dailyRows)) ?>,
          borderColor: '#198754',
          backgroundColor: 'rgba(25,135,84,0.15)',
          tension: 0.2,
          fill: true,
        }],
      },
      options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } },
      },
    });
    <?php endif; ?>
  </script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
