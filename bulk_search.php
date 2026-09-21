<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_ingest.php';

$tabTitle  = 'Bulk Search';
$activeNav = 'bulk_search';

$pasteText = '';
$results   = [];   // one row per input number
$summary   = ['found_customer' => 0, 'found_rider' => 0, 'found_both' => 0, 'not_found' => 0, 'total' => 0];
$errorMsg  = '';
$sort      = '';   // Created At ordering: '' = paste order, 'asc' = oldest first, 'desc' = newest first

const BULK_SEARCH_MAX = 1000; // safety cap

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['numbers'])) {
    $pasteText = (string)$_POST['numbers'];

    // Created At ordering — whitelisted, so a tampered value simply falls back
    // to paste order rather than reaching the sort comparison.
    $sort = (string)($_POST['sort'] ?? '');
    if (!in_array($sort, ['asc', 'desc'], true)) {
        $sort = '';
    }

    // Split on any whitespace / newline / comma, keep non-empty
    $rawTokens = preg_split('/[\s,;]+/', trim($pasteText)) ?: [];
    $rawTokens = array_values(array_filter($rawTokens, fn($t) => trim($t) !== ''));

    if ($rawTokens === []) {
        $errorMsg = 'Paste at least one mobile number.';
    } elseif (count($rawTokens) > BULK_SEARCH_MAX) {
        $errorMsg = 'Too many numbers (' . count($rawTokens) . '). Max is ' . BULK_SEARCH_MAX . ' per search.';
    } else {
        // Normalize each input to the 63xxxxxxxxxx format used in the DB.
        // Keep a map of normalized -> original input(s) so we can show what was pasted.
        $normalizedList = [];
        $inputByNorm    = [];
        foreach ($rawTokens as $raw) {
            [$norm, $recognized] = normalize_mobile_to_63($raw);
            $normalizedList[]        = $norm;
            $inputByNorm[$norm][]    = trim($raw);
        }
        $uniqueNorms = array_values(array_unique(array_filter($normalizedList, fn($n) => $n !== '')));

        $custByMobile  = [];
        $riderByMobile = [];

        if ($uniqueNorms !== []) {
            $pdo = get_pdo();
            $in  = implode(',', array_fill(0, count($uniqueNorms), '?'));

            // Customers (passengers). public.customer has current_lat but no
            // last_online_datetime / last_offline_datetime — those two columns
            // only exist on public.riders, so passenger rows show "—" for them.
            $custStmt = $pdo->prepare(
                "SELECT id, code, fname, mname, lname, mobile, email, status, is_verified, created_at, current_lat
                 FROM public.customer WHERE mobile IN ($in)"
            );
            $custStmt->execute($uniqueNorms);
            foreach ($custStmt->fetchAll() as $row) {
                $custByMobile[$row['mobile']] = $row;
            }

            // Riders (drivers)
            $riderStmt = $pdo->prepare(
                "SELECT id, code, first_name, middle_name, last_name, mobile_no, email_address, status, is_verified, created_at,
                        last_online_datetime, last_offline_datetime, current_lat
                 FROM public.riders WHERE mobile_no IN ($in)"
            );
            $riderStmt->execute($uniqueNorms);
            foreach ($riderStmt->fetchAll() as $row) {
                $riderByMobile[$row['mobile_no']] = $row;
            }
        }

        // Build one result row per unique input number (preserve paste order, dedupe)
        $seen = [];
        foreach ($normalizedList as $idx => $norm) {
            $origInput = $rawTokens[$idx];
            $dedupeKey = $norm !== '' ? $norm : 'raw:' . $origInput;
            if (isset($seen[$dedupeKey])) continue;
            $seen[$dedupeKey] = true;

            $cust  = $custByMobile[$norm]  ?? null;
            $rider = $riderByMobile[$norm] ?? null;

            if ($cust && $rider)      { $type = 'both';      $summary['found_both']++; }
            elseif ($cust)            { $type = 'customer';  $summary['found_customer']++; }
            elseif ($rider)           { $type = 'rider';     $summary['found_rider']++; }
            else                      { $type = 'none';      $summary['not_found']++; }

            $results[] = [
                'input'      => $origInput,
                'normalized' => $norm,
                'type'       => $type,
                'customer'   => $cust,
                'rider'      => $rider,
            ];
        }
        $summary['total'] = count($results);

        // Order by registration date when asked for. A number that is both a
        // passenger and a driver sorts by the earliest of its two records, so
        // the pair sits where that person first appeared in the system.
        // "Not Found" rows carry no date and always stay at the bottom.
        // usort() is stable, so equal dates keep the paste order.
        if ($sort !== '') {
            usort($results, static function (array $a, array $b) use ($sort): int {
                $tsA = bs_created_ts($a);
                $tsB = bs_created_ts($b);
                if ($tsA === null && $tsB === null) return 0;
                if ($tsA === null) return 1;
                if ($tsB === null) return -1;
                return $sort === 'asc' ? $tsA <=> $tsB : $tsB <=> $tsA;
            });
        }

        // Log the search
        log_activity('bulk_search', '', '', 'Searched ' . $summary['total'] . ' numbers: '
            . $summary['found_customer'] . ' passengers, ' . $summary['found_rider'] . ' drivers, '
            . $summary['found_both'] . ' both, ' . $summary['not_found'] . ' not found');
    }
}

function bs_status_badge($status): string
{
    return ((int)$status === 1)
        ? '<span class="badge pr-badge pr-badge-active">Active</span>'
        : '<span class="badge pr-badge pr-badge-inactive">Inactive</span>';
}

// Timestamp cell: registration (Created At), Last Online and Last Offline all
// use this. NULL/empty renders as an em dash; anything unparsable is shown as-is.
function bs_dt($v): string
{
    if ($v === null || $v === '') return '—';
    $ts = strtotime((string)$v);
    return $ts ? htmlspecialchars(date('Y-m-d h:i A', $ts)) : htmlspecialchars((string)$v);
}

// current_lat is varchar(255) in the DB and is NULL/'' for records that never
// reported a location (most passengers), so show an em dash rather than "".
function bs_lat($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

// Sort key for the "Created At" column: the earliest created_at among the
// records found for one input number (a number can be both a passenger and a
// driver), or null for "Not Found" rows, which always sort last.
function bs_created_ts(array $r): ?int
{
    $timestamps = [];
    foreach (['customer', 'rider'] as $key) {
        $createdAt = $r[$key]['created_at'] ?? null;
        $ts = ($createdAt === null || $createdAt === '') ? false : strtotime((string)$createdAt);
        if ($ts !== false && $ts !== null) {
            $timestamps[] = $ts;
        }
    }
    return $timestamps === [] ? null : min($timestamps);
}

require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3">Bulk Mobile Number Search</h4>
<p class="text-muted">Paste a list of mobile numbers (any format: 09xx, 9xx, +63xx). Searches both Passengers and Drivers at once.</p>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="pr-card">
  <div class="pr-card-title">Bulk Search</div>
  <form method="post">
    <label class="form-label" for="numbers">Mobile Numbers (one per line)</label>
    <textarea id="numbers" name="numbers" class="form-control font-monospace" rows="8"
              placeholder="09278448353&#10;09957930665&#10;09392490973&#10;..."><?= htmlspecialchars($pasteText) ?></textarea>
    <div class="pr-filter-actions mt-3">
      <button type="submit" class="btn btn-pr-primary">Search</button>
      <a href="bulk_search.php" class="btn btn-pr-secondary">Clear</a>
    </div>
  </form>
</div>

<?php if ($results !== []): ?>

  <!-- Summary -->
  <div class="row g-3 mb-4">
    <div class="col"><div class="card text-center"><div class="card-body py-3">
      <h4 class="mb-0"><?= $summary['total'] ?></h4><small class="text-muted">Total Searched</small>
    </div></div></div>
    <div class="col"><div class="card text-center"><div class="card-body py-3">
      <h4 class="text-success mb-0"><?= $summary['found_customer'] ?></h4><small class="text-muted">Passengers</small>
    </div></div></div>
    <div class="col"><div class="card text-center"><div class="card-body py-3">
      <h4 class="text-info mb-0"><?= $summary['found_rider'] ?></h4><small class="text-muted">Drivers</small>
    </div></div></div>
    <div class="col"><div class="card text-center"><div class="card-body py-3">
      <h4 class="text-warning mb-0"><?= $summary['found_both'] ?></h4><small class="text-muted">Both</small>
    </div></div></div>
    <div class="col"><div class="card text-center"><div class="card-body py-3">
      <h4 class="text-danger mb-0"><?= $summary['not_found'] ?></h4><small class="text-muted">Not Found</small>
    </div></div></div>
  </div>

  <?php
    // "Created At" column header is a sort toggle (plain form post, so the
    // pasted numbers survive the round-trip): paste order -> oldest -> newest
    // -> paste order.
    $sortNext  = $sort === 'asc' ? 'desc' : ($sort === 'desc' ? '' : 'asc');
    $sortLabel = $sort === 'asc' ? 'Oldest first' : ($sort === 'desc' ? 'Newest first' : '');
    $sortIcon  = $sort === 'asc' ? '&#9650;' : ($sort === 'desc' ? '&#9660;' : '&#8645;');
    $sortTitle = $sort === 'asc'
        ? 'Sorted by registration date, oldest first - click for newest first'
        : ($sort === 'desc'
            ? 'Sorted by registration date, newest first - click to go back to paste order'
            : 'Click to sort by registration date (oldest first)');
  ?>
  <div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <span class="fw-semibold">Results</span>
      <?php if ($sortLabel !== ''): ?>
        <span class="small fw-normal text-muted">Sort: <?= $sortLabel ?></span>
      <?php endif; ?>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive pr-table-scroll">
      <table class="table table-sm table-hover align-middle mb-0 pr-table pr-table-sticky">
        <thead>
          <tr>
            <th class="pr-sticky pr-sticky-1">#</th>
            <th class="pr-sticky pr-sticky-2">Number</th>
            <th class="pr-sticky pr-sticky-3">Found As</th>
            <th class="pr-sticky pr-sticky-4">Name</th>
            <th>Code</th><th>Status</th>
            <th>
              <form method="post" class="d-inline mb-0">
                <input type="hidden" name="numbers" value="<?= htmlspecialchars($pasteText) ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sortNext) ?>">
                <button type="submit" class="btn btn-link p-0 text-decoration-none"
                        style="color:inherit;font-weight:inherit"
                        title="<?= htmlspecialchars($sortTitle) ?>">
                  Created At <span aria-hidden="true"><?= $sortIcon ?></span>
                </button>
              </form>
            </th>
            <th>Last Online</th>
            <th>Last Offline</th>
            <th>Current Lat</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $i => $r): ?>
            <?php
              $rowClass = match ($r['type']) {
                  'none' => 'table-danger',
                  'both' => 'table-warning',
                  default => '',
              };
            ?>
            <?php if ($r['type'] === 'both'): ?>
              <!-- Passenger row -->
              <tr class="<?= $rowClass ?>">
                <td rowspan="2" class="pr-sticky pr-sticky-1"><?= $i + 1 ?></td>
                <td rowspan="2" class="font-monospace pr-sticky pr-sticky-2"><?= htmlspecialchars($r['input']) ?></td>
                <td class="pr-sticky pr-sticky-3"><span class="badge bg-success">Passenger</span></td>
                <td class="pr-sticky pr-sticky-4"><?= htmlspecialchars(trim($r['customer']['fname'] . ' ' . $r['customer']['lname'])) ?></td>
                <td><?= htmlspecialchars($r['customer']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['customer']['status']) ?></td>
                <td><?= bs_dt($r['customer']['created_at'] ?? null) ?></td>
                <td><?= bs_dt($r['customer']['last_online_datetime'] ?? null) ?></td>
                <td><?= bs_dt($r['customer']['last_offline_datetime'] ?? null) ?></td>
                <td><?= bs_lat($r['customer']['current_lat'] ?? null) ?></td>
                <td><a href="customer_show.php?id=<?= (int)$r['customer']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
              <tr class="<?= $rowClass ?>">
                <td class="pr-sticky pr-sticky-3"><span class="badge bg-info">Driver</span></td>
                <td class="pr-sticky pr-sticky-4"><?= htmlspecialchars(trim($r['rider']['first_name'] . ' ' . $r['rider']['last_name'])) ?></td>
                <td><?= htmlspecialchars($r['rider']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['rider']['status']) ?></td>
                <td><?= bs_dt($r['rider']['created_at'] ?? null) ?></td>
                <td><?= bs_dt($r['rider']['last_online_datetime'] ?? null) ?></td>
                <td><?= bs_dt($r['rider']['last_offline_datetime'] ?? null) ?></td>
                <td><?= bs_lat($r['rider']['current_lat'] ?? null) ?></td>
                <td><a href="rider_show.php?id=<?= (int)$r['rider']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php elseif ($r['type'] === 'customer'): ?>
              <tr>
                <td class="pr-sticky pr-sticky-1"><?= $i + 1 ?></td>
                <td class="font-monospace pr-sticky pr-sticky-2"><?= htmlspecialchars($r['input']) ?></td>
                <td class="pr-sticky pr-sticky-3"><span class="badge bg-success">Passenger</span></td>
                <td class="pr-sticky pr-sticky-4"><?= htmlspecialchars(trim($r['customer']['fname'] . ' ' . $r['customer']['lname'])) ?></td>
                <td><?= htmlspecialchars($r['customer']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['customer']['status']) ?></td>
                <td><?= bs_dt($r['customer']['created_at'] ?? null) ?></td>
                <td><?= bs_dt($r['customer']['last_online_datetime'] ?? null) ?></td>
                <td><?= bs_dt($r['customer']['last_offline_datetime'] ?? null) ?></td>
                <td><?= bs_lat($r['customer']['current_lat'] ?? null) ?></td>
                <td><a href="customer_show.php?id=<?= (int)$r['customer']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php elseif ($r['type'] === 'rider'): ?>
              <tr>
                <td class="pr-sticky pr-sticky-1"><?= $i + 1 ?></td>
                <td class="font-monospace pr-sticky pr-sticky-2"><?= htmlspecialchars($r['input']) ?></td>
                <td class="pr-sticky pr-sticky-3"><span class="badge bg-info">Driver</span></td>
                <td class="pr-sticky pr-sticky-4"><?= htmlspecialchars(trim($r['rider']['first_name'] . ' ' . $r['rider']['last_name'])) ?></td>
                <td><?= htmlspecialchars($r['rider']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['rider']['status']) ?></td>
                <td><?= bs_dt($r['rider']['created_at'] ?? null) ?></td>
                <td><?= bs_dt($r['rider']['last_online_datetime'] ?? null) ?></td>
                <td><?= bs_dt($r['rider']['last_offline_datetime'] ?? null) ?></td>
                <td><?= bs_lat($r['rider']['current_lat'] ?? null) ?></td>
                <td><a href="rider_show.php?id=<?= (int)$r['rider']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php else: ?>
              <tr class="table-danger">
                <td class="pr-sticky pr-sticky-1"><?= $i + 1 ?></td>
                <td class="font-monospace pr-sticky pr-sticky-2"><?= htmlspecialchars($r['input']) ?></td>
                <!-- Split across Found As / Name (instead of one colspan cell) so the
                     sticky columns keep the same structure as the rows above. -->
                <td class="pr-sticky pr-sticky-3"><span class="badge bg-danger">Not Found</span></td>
                <td class="pr-sticky pr-sticky-4">
                  <?php if ($r['normalized'] === ''): ?>
                    <small class="text-muted">(unrecognized format)</small>
                  <?php else: ?>
                    <small class="text-muted">(searched as <?= htmlspecialchars($r['normalized']) ?>)</small>
                  <?php endif; ?>
                </td>
                <td></td>
                <td></td>
                <td>--</td>
                <td>--</td>
                <td>--</td>
                <td>--</td>
                <td>--</td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
