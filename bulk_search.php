<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_ingest.php';

$tabTitle  = 'Bulk Search';
$activeNav = 'bulk_search';

$pasteText = '';
$results   = [];   // one row per input number
$summary   = ['found_customer' => 0, 'found_rider' => 0, 'found_both' => 0, 'not_found' => 0, 'total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicates' => 0];
$dupeGroups = [];  // normalized => ['count' => N, 'inputs' => [...raw variants]] for dupes pasted 2+ times
$errorMsg  = '';
$sort      = 'asc'; // Created At ordering: defaults to oldest first ('asc'); 'desc' = newest first
$dateFrom  = '';   // Last Online / Offline range start (YYYY-MM-DD, '' = open-ended)
$dateTo    = '';   // Last Online / Offline range end   (YYYY-MM-DD, '' = open-ended)
$fromTs    = null; // unix timestamp of $dateFrom 00:00:00 (null = no lower bound)
$toTs      = null; // unix timestamp of $dateTo 23:59:59   (null = no upper bound)
$rangeOn   = false; // true once at least one range bound is set
$filter    = '';   // Checker filter: '' = all records, 'valid' | 'invalid'

const BULK_SEARCH_MAX = 1000; // safety cap

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['numbers'])) {
    $pasteText = (string)$_POST['numbers'];

    // Created At ordering — whitelisted, and it defaults to oldest first:
    // a fresh search (which posts no sort at all) or a tampered value both
    // end up ordered oldest -> newest.
    $sort = (string)($_POST['sort'] ?? 'asc');
    if (!in_array($sort, ['asc', 'desc'], true)) {
        $sort = 'asc';
    }

    // Date range for the Last Online / Last Offline columns (inclusive whole
    // days). Format is whitelisted like $sort, so a tampered value simply
    // disables that bound instead of reaching the comparison below.
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo   = trim((string)($_POST['date_to'] ?? ''));
    if ($dateFrom !== '' && !bs_valid_date($dateFrom)) $dateFrom = '';
    if ($dateTo !== '' && !bs_valid_date($dateTo)) $dateTo = '';
    $ts     = $dateFrom !== '' ? strtotime($dateFrom . ' 00:00:00') : false;
    $fromTs = $ts === false ? null : (int)$ts;
    $ts     = $dateTo !== '' ? strtotime($dateTo . ' 23:59:59') : false;
    $toTs   = $ts === false ? null : (int)$ts;
    $rangeOn = ($fromTs !== null || $toTs !== null);

    // Checker + type filter ('' = all, else 'valid' | 'invalid' | 'passenger'
    // | 'driver' | 'both' | 'not_found' | 'duplicates') — whitelisted like
    // the sort toggle, so a tampered value simply shows every record.
    $filter = (string)($_POST['checker_filter'] ?? '');
    if (!in_array($filter, ['valid', 'invalid', 'passenger', 'driver', 'both', 'not_found', 'duplicates'], true)) {
        $filter = '';
    }

    // Split on any whitespace / newline / comma, keep non-empty
    $rawTokens = preg_split('/[\s,;]+/', trim($pasteText)) ?: [];
    $rawTokens = array_values(array_filter($rawTokens, fn($t) => trim($t) !== ''));

    if ($rawTokens === []) {
        $errorMsg = 'Paste at least one mobile number.';
    } elseif (count($rawTokens) > BULK_SEARCH_MAX) {
        $errorMsg = 'Too many numbers (' . count($rawTokens) . '). Max is ' . BULK_SEARCH_MAX . ' per search.';
    } elseif ($rangeOn && $fromTs !== null && $toTs !== null && $fromTs > $toTs) {
        $errorMsg = 'Invalid date range: "From" must be on or before "To".';
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
                "SELECT id, code, fname, mname, lname, mobile, email, status, is_verified, is_login, created_at, current_lat
                 FROM public.customer WHERE mobile IN ($in)"
            );
            $custStmt->execute($uniqueNorms);
            foreach ($custStmt->fetchAll() as $row) {
                $custByMobile[$row['mobile']] = $row;
            }

            // Riders (drivers)
            $riderStmt = $pdo->prepare(
                "SELECT id, code, first_name, middle_name, last_name, mobile_no, email_address, status, is_verified, is_login, created_at,
                        last_online_datetime, last_offline_datetime, current_lat
                 FROM public.riders WHERE mobile_no IN ($in)"
            );
            $riderStmt->execute($uniqueNorms);
            foreach ($riderStmt->fetchAll() as $row) {
                $riderByMobile[$row['mobile_no']] = $row;
            }
        }

        // Build one result row per unique input number (preserve paste order, dedupe).
        // Duplicate pastes are tracked in $dupeGroups so the Duplicates chip can
        // show how many extras were entered and the filter can list just them.
        $seen = [];
        $dupeCounts = [];
        foreach ($normalizedList as $idx => $norm) {
            $origInput = $rawTokens[$idx];
            $dedupeKey = $norm !== '' ? $norm : 'raw:' . $origInput;
            $dupeCounts[$dedupeKey] = ($dupeCounts[$dedupeKey] ?? 0) + 1;
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
                'dupe_count' => 1, // overwritten below from $dupeCounts
            ];
        }
        // Stamp how many times each unique number was pasted; >1 = duplicate row.
        foreach ($results as &$r) {
            $key = $r['normalized'] !== '' ? $r['normalized'] : 'raw:' . $r['input'];
            $r['dupe_count'] = $dupeCounts[$key] ?? 1;
            if ($r['dupe_count'] > 1) {
                $dupeGroups[$key] = [
                    'count'      => $r['dupe_count'],
                    'input'      => $r['input'],
                    'normalized' => $r['normalized'],
                ];
            }
        }
        unset($r);
        $summary['total'] = count($results);
        // Extra pastes beyond the first occurrence (e.g. 3x pasted = 2 dupes).
        $summary['duplicates'] = array_sum(array_map(
            static fn(array $g): int => $g['count'] - 1,
            $dupeGroups
        ));

        // Checker totals across ALL found records — computed before the
        // optional Valid/Invalid filter below so the summary cards always
        // show the full picture. Passengers: Valid = is_login = 1 or Current Lat not empty.
        // Drivers: Valid = Last Online or Last Offline inside the picked range.
        foreach ($results as $r) {
            foreach (['customer', 'rider'] as $key) {
                $rec = $r[$key] ?? null;
                if ($rec === null) continue;
                if (bs_is_valid($rec, $fromTs, $toTs)) $summary['valid']++;
                else $summary['invalid']++;
            }
        }

        // Order by registration date (always on: oldest first by default,
        // newest first when toggled). A number that is both a passenger and a
        // driver sorts by the earliest of its two records, so the pair sits
        // where that person first appeared in the system.
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

        // Result filter: keeps only the matching rows. 'valid' / 'invalid' keep
        // only the record rows whose Checker verdict matches, flattened to one
        // row per record — a "both" number can contribute just its matching
        // side. 'passenger' / 'driver' / 'both' / 'not_found' / 'duplicates'
        // keep the grouped input-number rows of that kind. Runs after the sort
        // so the order is preserved. Not Found rows have no Checker and never
        // match valid/invalid.
        if ($filter === 'valid' || $filter === 'invalid') {
            $wantValid = ($filter === 'valid');
            $flat = [];
            foreach ($results as $r) {
                foreach (['customer', 'rider'] as $key) {
                    $rec = $r[$key] ?? null;
                    if ($rec === null || bs_is_valid($rec, $fromTs, $toTs) !== $wantValid) continue;
                    $flat[] = [
                        'input'      => $r['input'],
                        'normalized' => $r['normalized'],
                        'type'       => $key,
                        'customer'   => $key === 'customer' ? $rec : null,
                        'rider'      => $key === 'rider' ? $rec : null,
                        'dupe_count' => $r['dupe_count'] ?? 1,
                    ];
                }
            }
            $results = $flat;
        } elseif ($filter !== '') {
            $results = array_values(array_filter(
                $results,
                static function (array $r) use ($filter): bool {
                    if ($filter === 'passenger')  return $r['type'] === 'customer';
                    if ($filter === 'driver')     return $r['type'] === 'rider';
                    if ($filter === 'both')       return $r['type'] === 'both';
                    if ($filter === 'duplicates') return ($r['dupe_count'] ?? 1) > 1;
                    return $r['type'] === 'none'; // 'not_found' chip
                }
            ));
        }

        // Log the search (range bounds and filter included when used)
        log_activity('bulk_search', '', '', 'Searched ' . $summary['total'] . ' numbers: '
            . $summary['found_customer'] . ' passengers, ' . $summary['found_rider'] . ' drivers, '
            . $summary['found_both'] . ' both, ' . $summary['not_found'] . ' not found'
            . ($summary['duplicates'] > 0 ? ', ' . $summary['duplicates'] . ' duplicate pastes' : '')
            . ($rangeOn ? ' | range ' . ($dateFrom !== '' ? $dateFrom : '...') . ' to ' . ($dateTo !== '' ? $dateTo : '...') : '')
            . ($filter !== '' ? ' | filtered: ' . $filter . ' only' : ''));
    }
}

function bs_status_badge($status): string
{
    return ((int)$status === 1)
        ? '<span class="badge pr-badge pr-badge-active">Active</span>'
        : '<span class="badge pr-badge pr-badge-inactive">Inactive</span>';
}

// is_login cell: same green/grey "Logged In / Logged Out" meaning as the
// detail pages; NULL (column missing on old rows) renders as an em dash.
function bs_login_badge($v): string
{
    if ($v === null || $v === '') return '—';
    return ((int)$v === 1)
        ? '<span class="badge bg-success">Logged In</span>'
        : '<span class="badge bg-secondary">Logged Out</span>';
}

// Timestamp cell: registration (Created At), Last Online and Last Offline all
// use this. NULL/empty renders as an em dash; anything unparsable is shown as-is.
function bs_dt($v): string
{
    if ($v === null || $v === '') return '—';
    $ts = strtotime((string)$v);
    return $ts ? htmlspecialchars(date('Y-m-d h:i A', $ts)) : htmlspecialchars((string)$v);
}

// Calendar-date whitelist for the Last Online / Offline range pickers
// (rejects e.g. 2026-02-31, which strtotime would silently roll over).
// Same philosophy as the $sort whitelist: a tampered value just disables
// that bound rather than erroring out.
function bs_valid_date(string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// current_lat is varchar(255) in the DB and is NULL/'' for records that never
// reported a location (most passengers), so show an em dash rather than "".
function bs_lat($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

// "Checker" column. Shared verdict, also used for the summary totals and the
// Valid / Invalid list filter. Drivers (riders) are ruled purely by the date
// range: Valid when Last Online OR Last Offline falls inside it (inclusive
// whole days; empty or unparsable timestamps are never in range). Passengers
// have no last_online_datetime / last_offline_datetime columns at all — they
// are Valid when is_login = 1 or Current Lat is not empty. With no range
// picked the bounds are open, so any parseable timestamp counts as in range
// for drivers.
// A record counts as a driver when the rider SELECT columns are present
// (array_key_exists, because a NULL timestamp would fail isset()).
function bs_is_valid(array $rec, ?int $fromTs, ?int $toTs): bool
{
    $isDriver = array_key_exists('last_online_datetime', $rec)
        || array_key_exists('last_offline_datetime', $rec);

    if (!$isDriver) {
        // Passenger (customer record): logged in, or has a location fix.
        if ((int)($rec['is_login'] ?? 0) === 1) {
            return true;
        }
        return isset($rec['current_lat']) && trim((string)$rec['current_lat']) !== '';
    }

    foreach (['last_online_datetime', 'last_offline_datetime'] as $key) {
        $v = $rec[$key] ?? null;
        if ($v === null || trim((string)$v) === '') continue;
        $ts = strtotime((string)$v);
        if ($ts === false) continue;
        if (($fromTs === null || $ts >= $fromTs) && ($toTs === null || $ts <= $toTs)) {
            return true;
        }
    }
    return false;
}

// "Checker" column badge — the only column that carries Valid / Invalid.
function bs_checker(array $rec, ?int $fromTs = null, ?int $toTs = null): string
{
    return bs_is_valid($rec, $fromTs, $toTs)
        ? '<span class="badge pr-badge pr-badge-valid">Valid</span>'
        : '<span class="badge pr-badge pr-badge-invalid">Invalid</span>';
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

// Human-readable label for the active result filter chip.
function bs_filter_label(string $filter): string
{
    return match ($filter) {
        'valid'      => 'Valid',
        'invalid'    => 'Invalid',
        'passenger'  => 'Passengers',
        'driver'     => 'Drivers',
        'both'       => 'Both',
        'not_found'  => 'Not found',
        'duplicates' => 'Duplicates',
        default      => 'All',
    };
}

// Small "x2" style badge shown next to a Number cell pasted more than once.
function bs_dupe_badge($count): string
{
    $n = (int)($count ?? 1);
    if ($n <= 1) return '';
    return ' <span class="badge bg-warning text-dark" title="Pasted ' . $n . ' times in this search">x' . $n . '</span>';
}

require __DIR__ . '/includes/header.php';
?>

<div class="pr-page-head">
  <h4 class="mb-1">Bulk Mobile Number Search</h4>
  <div class="pr-page-sub">
    <span class="pr-hint">Paste mobile numbers (one per line). Any format: 09xx, 9xx, +63xx.</span>
    <details class="pr-how-it-works">
      <summary>ⓘ How it works</summary>
      <p class="text-muted">
        Paste a list of mobile numbers (any format: 09xx, 9xx, +63xx). Searches both Passengers and Drivers at once.
        Optionally pick a From / To date range below to rule the Checker column:
        Drivers are <span class="badge pr-badge pr-badge-valid">Valid</span> when their Last Online or Last Offline
        falls inside the range; passengers are <span class="badge pr-badge pr-badge-valid">Valid</span> when is_login = 1 or Current Lat
        is not empty; everything else is <span class="badge pr-badge pr-badge-invalid">Invalid</span>.
        Click the Valid / Invalid totals below to list just those records, or the
        Passengers / Drivers / Both / Not found totals to list just that type.
        Pasted a number twice? The Duplicates chip lists the repeated rows.
      </p>
    </details>
  </div>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="pr-card pr-bulk-search-card">
  <div class="pr-card-title">Bulk Search</div>
  <form method="post">
    <div class="pr-bulk-search-grid">
      <div class="pr-bulk-search-left">
        <label class="form-label" for="numbers">Mobile Numbers (one per line)</label>
        <textarea id="numbers" name="numbers" class="form-control font-monospace pr-bulk-textarea" rows="5"
                  placeholder="09278448353&#10;09957930665&#10;09392490973&#10;..."><?= htmlspecialchars($pasteText) ?></textarea>
      </div>
      <div class="pr-bulk-search-right">
        <div class="pr-bulk-dates">
          <div class="pr-filter-field">
            <label for="date_from">Last Online / Offline From</label>
            <input type="date" id="date_from" name="date_from" class="form-control"
                   value="<?= htmlspecialchars($dateFrom) ?>">
          </div>
          <div class="pr-filter-field">
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" class="form-control"
                   value="<?= htmlspecialchars($dateTo) ?>">
          </div>
        </div>
        <div class="pr-bulk-actions">
          <button type="submit" class="btn btn-pr-primary">Search</button>
          <a href="bulk_search.php" class="btn btn-pr-secondary">Clear</a>
        </div>
      </div>
    </div>
  </form>
</div>

<?php if ($summary['total'] > 0): ?>

  <!-- Summary chips. These submit buttons re-run the same search with
       checker_filter set so the table lists only those records
       (Valid/Invalid = flattened record rows, the rest = grouped input rows);
       the "All" chip clears the filter again. -->
  <form method="post" id="pr-chip-filter-form">
    <input type="hidden" name="numbers" value="<?= htmlspecialchars($pasteText) ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
    <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
    <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
  </form>

  <?php
    // "Created At" column header is a sort toggle (plain form post, so the
    // pasted numbers survive the round-trip): oldest first <-> newest first.
    // Oldest first is the default for every fresh search.
    $sortNext  = $sort === 'asc' ? 'desc' : 'asc';
    $sortLabel = $sort === 'asc' ? 'Oldest first' : 'Newest first';
    $sortIcon  = $sort === 'asc' ? '&#9650;' : '&#9660;';
    $sortTitle = $sort === 'asc'
        ? 'Sorted by registration date, oldest first - click for newest first'
        : 'Sorted by registration date, newest first - click for oldest first';

    // Active Last Online / Offline date range — shown in the results header
    // Active date range + Checker filter — reflected in the results header
    // and in the Checker column tooltip.
    $rangeLabel = $rangeOn
        ? (($dateFrom !== '' ? $dateFrom : 'start') . ' -> ' . ($dateTo !== '' ? $dateTo : 'today'))
        : '';
    $checkerTitle = 'Drivers: Valid when Last Online or Last Offline falls inside the picked date range. '
        . 'Passengers: Valid when is_login = 1 or Current Lat is not empty.'
        . ($rangeOn ? ' Active range: ' . $rangeLabel . '.' : ' No range picked, so any timestamp counts as in range.');
    $headerMeta = [];
    if ($rangeOn) {
        $headerMeta[] = 'Range: ' . $rangeLabel . ' — Checker: drivers ruled by range, passengers by is_login / Current Lat';
    }
    if ($filter !== '') {
        $headerMeta[] = 'Filter: showing ' . bs_filter_label($filter) . ' only (' . count($results) . ' of ' . $summary['total'] . ')';
    }
    if ($sortLabel !== '') {
        $headerMeta[] = 'Sort: ' . $sortLabel;
    }
  ?>
  <div class="pr-card pr-bulk-results-card">
    <div class="pr-bulk-results-head">
      <div class="pr-bulk-results-title">
        <span class="pr-card-title mb-0">Results</span>
        <?php if ($headerMeta !== []): ?>
        <span class="pr-bulk-range text-muted"><?= htmlspecialchars(implode(chr(32).chr(124).chr(32), $headerMeta)) ?></span>
        <?php endif; ?>
      </div>
      <div class="pr-chips" role="group" aria-label="Filter results">
        <button type="submit" form="pr-chip-filter-form" name="checker_filter" value=""
                class="pr-chip pr-chip-all<?= $filter === '' ? ' active' : '' ?><?= $summary['total'] === 0 ? ' is-zero' : '' ?>"
                title="Show every record">All <?= $summary['total'] ?></button>
        <button type="submit" form="pr-chip-filter-form" name="checker_filter" value="valid"
                class="pr-chip pr-chip-valid<?= $filter === 'valid' ? ' active' : '' ?><?= $summary['valid'] === 0 ? ' is-zero' : '' ?>"
                title="Show only Valid records (drivers: timestamp in range, passengers: is_login = 1 or Current Lat set)">Valid <?= $summary['valid'] ?></button>
        <button type="submit" form="pr-chip-filter-form" name="checker_filter" value="invalid"
                class="pr-chip pr-chip-invalid<?= $filter === 'invalid' ? ' active' : '' ?><?= $summary['invalid'] === 0 ? ' is-zero' : '' ?>"
                title="Show only Invalid records">Invalid <?= $summary['invalid'] ?></button>
        <span class="pr-chip-sep" aria-hidden="true"></span>
        <button type="submit" form="pr-chip-filter-form" name="checker_filter" value="passenger"
                class="pr-chip pr-chip-customer<?= $filter === 'passenger' ? ' active' : '' ?><?= $summary['found_customer'] === 0 ? ' is-zero' : '' ?>"
                title="Show only passenger rows in this search">Passengers <?= $summary['found_customer'] ?></button>
        <button type="submit" form="pr-chip-filter-form" name="checker_filter" value="driver"
                class="pr-chip pr-chip-rider<?= $filter === 'driver' ? ' active' : '' ?><?= $summary['found_rider'] === 0 ? ' is-zero' : '' ?>"
                title="Show only driver rows in this search">Drivers <?= $summary['found_rider'] ?></button>
        <button type="submit" form="pr-chip-filter-form" name="checker_filter" value="both"
                class="pr-chip pr-chip-both<?= $filter === 'both' ? ' active' : '' ?><?= $summary['found_both'] === 0 ? ' is-zero' : '' ?>"
                title="Show only numbers found as both passenger and driver">Both <?= $summary['found_both'] ?></button>
        <button type="submit" form="pr-chip-filter-form" name="checker_filter" value="not_found"
                class="pr-chip pr-chip-notfound<?= $filter === 'not_found' ? ' active' : '' ?><?= $summary['not_found'] === 0 ? ' is-zero' : '' ?>"
                title="Show only numbers not found">Not found <?= $summary['not_found'] ?></button>
        <button type="submit" form="pr-chip-filter-form" name="checker_filter" value="duplicates"
                class="pr-chip pr-chip-dupe<?= $filter === 'duplicates' ? ' active' : '' ?><?= $summary['duplicates'] === 0 ? ' is-zero' : '' ?>"
                title="Show only numbers pasted more than once in this search">Duplicates <?= $summary['duplicates'] ?></button>
      </div>
      <div class="pr-bulk-results-meta text-muted">Showing <?= count($results) ?> of <?= ($summary['valid'] + $summary['invalid']) ?> &middot; Sorted: <?= htmlspecialchars($sortLabel) ?></div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive pr-table-scroll pr-bulk-table-wrap">
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
                <!-- Keep the date range and Checker filter alive across the sort round-trip -->
                <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                <input type="hidden" name="checker_filter" value="<?= htmlspecialchars($filter) ?>">
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
            <th>Is Login</th>
            <th title="<?= htmlspecialchars($checkerTitle) ?>">Checker</th>
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
                <td rowspan="2" class="font-monospace pr-sticky pr-sticky-2"><?= htmlspecialchars($r['input']) ?><?= bs_dupe_badge($r['dupe_count'] ?? 1) ?></td>
                <td class="pr-sticky pr-sticky-3"><span class="badge bg-success">Passenger</span></td>
                <td class="pr-sticky pr-sticky-4"><?= htmlspecialchars(trim($r['customer']['fname'] . ' ' . $r['customer']['lname'])) ?></td>
                <td><?= htmlspecialchars($r['customer']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['customer']['status']) ?></td>
                <td><?= bs_dt($r['customer']['created_at'] ?? null) ?></td>
                <td><?= bs_dt($r['customer']['last_online_datetime'] ?? null) ?></td>
                <td><?= bs_dt($r['customer']['last_offline_datetime'] ?? null) ?></td>
                <td><?= bs_lat($r['customer']['current_lat'] ?? null) ?></td>
                <td><?= bs_login_badge($r['customer']['is_login'] ?? null) ?></td>
                <td><?= bs_checker($r['customer'], $fromTs, $toTs) ?></td>
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
                <td><?= bs_login_badge($r['rider']['is_login'] ?? null) ?></td>
                <td><?= bs_checker($r['rider'], $fromTs, $toTs) ?></td>
                <td><a href="rider_show.php?id=<?= (int)$r['rider']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php elseif ($r['type'] === 'customer'): ?>
              <tr>
                <td class="pr-sticky pr-sticky-1"><?= $i + 1 ?></td>
                <td class="font-monospace pr-sticky pr-sticky-2"><?= htmlspecialchars($r['input']) ?><?= bs_dupe_badge($r['dupe_count'] ?? 1) ?></td>
                <td class="pr-sticky pr-sticky-3"><span class="badge bg-success">Passenger</span></td>
                <td class="pr-sticky pr-sticky-4"><?= htmlspecialchars(trim($r['customer']['fname'] . ' ' . $r['customer']['lname'])) ?></td>
                <td><?= htmlspecialchars($r['customer']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['customer']['status']) ?></td>
                <td><?= bs_dt($r['customer']['created_at'] ?? null) ?></td>
                <td><?= bs_dt($r['customer']['last_online_datetime'] ?? null) ?></td>
                <td><?= bs_dt($r['customer']['last_offline_datetime'] ?? null) ?></td>
                <td><?= bs_lat($r['customer']['current_lat'] ?? null) ?></td>
                <td><?= bs_login_badge($r['customer']['is_login'] ?? null) ?></td>
                <td><?= bs_checker($r['customer'], $fromTs, $toTs) ?></td>
                <td><a href="customer_show.php?id=<?= (int)$r['customer']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php elseif ($r['type'] === 'rider'): ?>
              <tr>
                <td class="pr-sticky pr-sticky-1"><?= $i + 1 ?></td>
                <td class="font-monospace pr-sticky pr-sticky-2"><?= htmlspecialchars($r['input']) ?><?= bs_dupe_badge($r['dupe_count'] ?? 1) ?></td>
                <td class="pr-sticky pr-sticky-3"><span class="badge bg-info">Driver</span></td>
                <td class="pr-sticky pr-sticky-4"><?= htmlspecialchars(trim($r['rider']['first_name'] . ' ' . $r['rider']['last_name'])) ?></td>
                <td><?= htmlspecialchars($r['rider']['code'] ?? '') ?></td>
                <td><?= bs_status_badge($r['rider']['status']) ?></td>
                <td><?= bs_dt($r['rider']['created_at'] ?? null) ?></td>
                <td><?= bs_dt($r['rider']['last_online_datetime'] ?? null) ?></td>
                <td><?= bs_dt($r['rider']['last_offline_datetime'] ?? null) ?></td>
                <td><?= bs_lat($r['rider']['current_lat'] ?? null) ?></td>
                <td><?= bs_login_badge($r['rider']['is_login'] ?? null) ?></td>
                <td><?= bs_checker($r['rider'], $fromTs, $toTs) ?></td>
                <td><a href="rider_show.php?id=<?= (int)$r['rider']['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php else: ?>
              <tr class="table-danger">
                <td class="pr-sticky pr-sticky-1"><?= $i + 1 ?></td>
                <td class="font-monospace pr-sticky pr-sticky-2"><?= htmlspecialchars($r['input']) ?><?= bs_dupe_badge($r['dupe_count'] ?? 1) ?></td>
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
                <td>--</td>
                <td>--</td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($results === [] && $filter !== ''): ?>
            <tr>
              <td colspan="13" class="text-center text-muted py-4">
                No <?= htmlspecialchars(bs_filter_label($filter)) ?> records found — click "All" above to reset the filter.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
