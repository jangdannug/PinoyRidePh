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

const BULK_SEARCH_MAX = 1000; // safety cap

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['numbers'])) {
    $pasteText = (string)$_POST['numbers'];

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

            // Customers (passengers)
            $custStmt = $pdo->prepare(
                "SELECT id, code, fname, mname, lname, mobile, email, status, is_verified, created_at
                 FROM public.customer WHERE mobile IN ($in)"
            );
            $custStmt->execute($uniqueNorms);
            foreach ($custStmt->fetchAll() as $row) {
                $custByMobile[$row['mobile']] = $row;
            }

            // Riders (drivers)
            $riderStmt = $pdo->prepare(
                "SELECT id, code, first_name, middle_name, last_name, mobile_no, email_address, status, is_verified, created_at
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

  <div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Results</div>
    <div class="card-body p-0">
      <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 pr-table">
        <thead>
          <tr>
            <th>#</th><th>Number</th><th>Found As</th><th>Name</th><th>Code</th><th>Status</th><th>Action</th>
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
                <td rowspan="2"><?= $i + 1 ?></td>
                <td rowspan="2" class="font-monospace"><?= htmlspecialchars($r['input']) ?></td>
                <td><span class="badge bg-success">Passenger</span></td>
                <td><?= htmlspecialchars(trim($r['customer']['fname'] . ' ' . $r['customer']['lname'])) ?></td>
                <td><?= htmlspecialchars($r['customer']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['customer']['status']) ?></td>
                <td><a href="customer_show.php?id=<?= (int)$r['customer']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
              <tr class="<?= $rowClass ?>">
                <td><span class="badge bg-info">Driver</span></td>
                <td><?= htmlspecialchars(trim($r['rider']['first_name'] . ' ' . $r['rider']['last_name'])) ?></td>
                <td><?= htmlspecialchars($r['rider']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['rider']['status']) ?></td>
                <td><a href="rider_show.php?id=<?= (int)$r['rider']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php elseif ($r['type'] === 'customer'): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td class="font-monospace"><?= htmlspecialchars($r['input']) ?></td>
                <td><span class="badge bg-success">Passenger</span></td>
                <td><?= htmlspecialchars(trim($r['customer']['fname'] . ' ' . $r['customer']['lname'])) ?></td>
                <td><?= htmlspecialchars($r['customer']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['customer']['status']) ?></td>
                <td><a href="customer_show.php?id=<?= (int)$r['customer']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php elseif ($r['type'] === 'rider'): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td class="font-monospace"><?= htmlspecialchars($r['input']) ?></td>
                <td><span class="badge bg-info">Driver</span></td>
                <td><?= htmlspecialchars(trim($r['rider']['first_name'] . ' ' . $r['rider']['last_name'])) ?></td>
                <td><?= htmlspecialchars($r['rider']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['rider']['status']) ?></td>
                <td><a href="rider_show.php?id=<?= (int)$r['rider']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php else: ?>
              <tr class="table-danger">
                <td><?= $i + 1 ?></td>
                <td class="font-monospace"><?= htmlspecialchars($r['input']) ?></td>
                <td colspan="4"><span class="badge bg-danger">Not Found</span>
                  <?php if ($r['normalized'] === ''): ?>
                    <small class="text-muted">(unrecognized format)</small>
                  <?php else: ?>
                    <small class="text-muted">(searched as <?= htmlspecialchars($r['normalized']) ?>)</small>
                  <?php endif; ?>
                </td>
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
