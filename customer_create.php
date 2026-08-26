<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_ingest.php';
$tabTitle = 'Add Passenger(s)';

// Unified passenger entry: one page, two input modes converging on the same
// review -> confirm -> insert pipeline (same shape as import_customer.php):
//   - "Single entry"  — classic form, one passenger at a time
//   - "Paste rows"    — select columns in Excel / Google Sheets, Ctrl+C,
//                       paste here (TSV). No more export-to-CSV-and-upload.
// Both share validate_customer_fields() / insert_customer_record() via
// includes/customer_ingest.php, so anything registered here is structurally
// identical to CSV imports and app registrations.
//
// Duplicate mobile numbers are caught at REVIEW time — against the database
// AND within the submission itself — instead of being discovered after
// confirming.

const PASTE_MAX_ROWS = 500; // safety cap per submission

$mode       = 'form';   // form | preview | ingest
$errorMsg   = '';
$formErrors = [];
$rows       = [];       // review rows for the current submission
$results    = [];       // ingest outcomes
$source     = 'single'; // single | batch
$pasteText  = '';

$firstName = '';
$lastName  = '';
$mobile    = '';
$gender    = '';
$address   = '';

// ---- TSV parsing (the clipboard format of Excel / Google Sheets) -----------
// Quoted cells may contain tabs and newlines; "" inside quotes is a literal
// quote. Accepts \n and \r\n record separators and ignores trailing blanks.
function parse_tsv(string $text): array
{
    $text    = str_replace(["\r\n", "\r"], "\n", $text);
    $records = [];
    $record  = [];
    $field   = '';
    $inQuote = false;
    $len     = strlen($text);

    for ($i = 0; $i < $len; $i++) {
        $ch = $text[$i];
        if ($inQuote) {
            if ($ch === '"') {
                if ($i + 1 < $len && $text[$i + 1] === '"') {
                    $field .= '"';
                    $i++;
                } else {
                    $inQuote = false;
                }
            } else {
                $field .= $ch;
            }
        } elseif ($ch === '"' && $field === '') {
            $inQuote = true;
        } elseif ($ch === "\t") {
            $record[] = $field;
            $field = '';
        } elseif ($ch === "\n") {
            $record[] = $field;
            $records[] = $record;
            $record = [];
            $field = '';
        } else {
            $field .= $ch;
        }
    }
    if ($field !== '' || $record !== []) {
        $record[] = $field;
        $records[] = $record;
    }
    return $records;
}

// Guess which pasted column holds which field from a header row, so any
// column order works when headers are present. Recognizes both simplified
// headers (First Name, Mobile, Gender, Address) and the actual Google Form
// spreadsheet headers.
function map_paste_columns(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $idx => $cell) {
        $raw = strtolower(trim((string)$cell));
        $h = preg_replace('/[^a-z]/', '', $raw);
        if ($h === '') {
            continue;
        }
        if (!isset($map['first_name']) && ($h === 'firstname' || $h === 'fname' || str_contains($h, 'first') || str_contains($h, 'given'))) {
            $map['first_name'] = $idx;
        } elseif (!isset($map['last_name']) && ($h === 'lastname' || $h === 'lname' || $h === 'surname' || str_contains($h, 'last') || str_contains($h, 'family'))) {
            $map['last_name'] = $idx;
        } elseif (!isset($map['mobile']) && (str_contains($h, 'mobile') || str_contains($h, 'phone') || str_contains($h, 'cell'))) {
            $map['mobile'] = $idx;
        } elseif (!isset($map['gender']) && ($h === 'gender' || $h === 'sex')) {
            $map['gender'] = $idx;
        } elseif (!isset($map['address']) && (str_contains($h, 'address') || $h === 'addr')) {
            $map['address'] = $idx;
        } elseif (!isset($map['email']) && str_contains($h, 'email')) {
            $map['email'] = $idx;
        }
    }
    return $map;
}

function looks_like_header_row(array $row): bool
{
    $m = map_paste_columns($row);
    // Accept as header if we find at least first_name + last_name + mobile,
    // or if we match >=3 fields (handles partial header recognition).
    if (isset($m['first_name'], $m['last_name'], $m['mobile'])) {
        return true;
    }
    return count($m) >= 3;
}

// Detect if a headerless paste looks like the full 13-column Google Form
// export (Timestamp in col 0, "Yes"/"No" consent in col 1, data in cols 2+)
// vs the simplified 5-column format (First|Last|Mobile|Gender|Address).
function guess_paste_is_full_spreadsheet(array $firstDataRow): bool
{
    // If we have >=8 columns, it's almost certainly the full spreadsheet
    if (count($firstDataRow) >= 8) {
        return true;
    }
    // If col 0 looks like a timestamp and col 1 is "yes"/"no", it's the form
    $col0 = strtolower(trim((string)($firstDataRow[0] ?? '')));
    $col1 = strtolower(trim((string)($firstDataRow[1] ?? '')));
    if (($col1 === 'yes' || $col1 === 'no') && preg_match('/\d{1,2}\/\d{1,2}\/\d{2,4}/', $col0)) {
        return true;
    }
    return false;
}

// Turn pasted text into raw field sets. Header row (any column order) is
// detected automatically; otherwise the full 13-column Google Form layout
// is assumed if the paste has >=8 columns or looks like timestamped form
// data, falling back to the simple 5-column First|Last|Mobile|Gender|Address
// for short pastes.
//
// Full 13-column Google Form layout:
//   [0]  Timestamp (skipped)
//   [1]  Consent - Yes/No (skipped)
//   [2]  First Name
//   [3]  Last Name
//   [4]  (unused)
//   [5]  (unused)
//   [6]  Gender
//   [7]  Address
//   [8]  Mobile Number
//   [9]  (unused)
//   [10] (unused/notes)
//   [11] Email
//   [12] (unused)
function build_raw_rows_from_paste(string $text): array
{
    $records = parse_tsv($text);
    $records = array_values(array_filter(
        $records,
        fn(array $r): bool => array_filter(array_map(fn($c) => trim((string)$c), $r)) !== []
    ));
    if ($records === []) {
        return [];
    }

    $hasHead  = looks_like_header_row($records[0]);
    $dataRows = $hasHead ? array_slice($records, 1) : $records;

    if ($hasHead) {
        $map = map_paste_columns($records[0]);
    } elseif (!empty($dataRows) && guess_paste_is_full_spreadsheet($dataRows[0])) {
        // Full 13-column Google Form export (same indices as the real spreadsheet)
        $map = [
            'first_name' => 2,
            'last_name'  => 3,
            'gender'     => 6,
            'address'    => 7,
            'mobile'     => 8,
            'email'      => 11,
        ];
    } else {
        // Simple 5-column paste: First | Last | Mobile | Gender | Address
        $map = ['first_name' => 0, 'last_name' => 1, 'mobile' => 2, 'gender' => 3, 'address' => 4];
    }

    $out = [];
    foreach ($dataRows as $r) {
        $get = fn(string $key): string => isset($map[$key]) ? trim((string)($r[$map[$key]] ?? '')) : '';
        $out[] = [
            'first_name' => $get('first_name'),
            'last_name'  => $get('last_name'),
            'mobile'     => $get('mobile'),
            'gender'     => $get('gender'),
            'address'    => $get('address'),
        ];
    }
    return $out;
}

// Validate raw rows into reviewable rows. States:
//   ok | warn           -> included in the confirm payload
//   invalid | duplicate -> excluded, must be fixed or dropped before confirming
function assemble_review_rows(array $rawRows): array
{
    $now = date('Y-m-d H:i:s');

    $rows = [];
    foreach ($rawRows as $raw) {
        $mapped = validate_customer_fields(
            $raw['first_name'],
            $raw['last_name'],
            $raw['mobile'],
            $raw['gender'],
            $raw['address']
        );
        $rows[] = ['mapped' => $mapped, 'state' => 'ok', 'reasons' => []];
    }

    // Hard blockers: missing required fields / unrecognizable mobile format
    foreach ($rows as &$row) {
        $m       = $row['mapped'];
        $missing = [];
        if ($m['first_name'] === '') $missing[] = 'first name';
        if ($m['last_name'] === '')  $missing[] = 'last name';
        if ($m['mobile_no'] === '')  $missing[] = 'mobile number';
        if ($missing !== []) {
            $row['state']     = 'invalid';
            $row['reasons'][] = 'Missing ' . implode(', ', $missing);
        } elseif (!$m['mobile_recognized']) {
            $row['state']     = 'invalid';
            $row['reasons'][] = 'Mobile not in a recognizable PH format (09xx…, 9xx… or 63xx…)';
        }
    }
    unset($row);

    // Database duplicates — one query for all candidate mobiles at once
    $candidates = [];
    foreach ($rows as $row) {
        if ($row['state'] !== 'invalid' && $row['mapped']['mobile_no'] !== '') {
            $candidates[$row['mapped']['mobile_no']] = true;
        }
    }
    $existing = [];
    if ($candidates !== []) {
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $st = get_pdo()->prepare("SELECT mobile FROM public.customer WHERE mobile IN ($in)");
        $st->execute(array_keys($candidates));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mob) {
            $existing[$mob] = true;
        }
    }

    $seenInBatch = [];
    foreach ($rows as &$row) {
        $mob = $row['mapped']['mobile_no'];
        if ($row['state'] === 'invalid' || $mob === '') {
            continue;
        }
        if (isset($existing[$mob])) {
            $row['state']     = 'duplicate';
            $row['reasons'][] = 'Mobile already exists in the database';
        } elseif (isset($seenInBatch[$mob])) {
            $row['state']     = 'duplicate';
            $row['reasons'][] = 'Repeated later in this same submission';
        }
        $seenInBatch[$mob] = true;

        if ($row['state'] === 'ok' && !empty($row['mapped']['issues'])) {
            $row['state'] = 'warn';
        }
    }
    unset($row);

    foreach ($rows as &$row) {
        $row['payload'] = null;
        if ($row['state'] === 'ok' || $row['state'] === 'warn') {
            $row['payload'] = [
                'first_name'        => $row['mapped']['first_name'],
                'last_name'         => $row['mapped']['last_name'],
                'mobile_no'         => $row['mapped']['mobile_no'],
                'gender'            => $row['mapped']['gender'],
                'permanent_address' => $row['mapped']['permanent_address'],
                'created_at'        => $now,
            ];
        }
    }
    unset($row);

    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'], $_POST['payload']) && $_POST['confirm'] === '1') {
    // -----------------------------------------------------------
    // Step 3: confirmed — ingest the reviewed payload rows
    // -----------------------------------------------------------
    $mode   = 'ingest';
    $source = ($_POST['source'] ?? 'batch') === 'single' ? 'single' : 'batch';

    $decoded = json_decode($_POST['payload'], true);
    if (!is_array($decoded) || $decoded === []) {
        $errorMsg = 'Nothing to register — go back and review the rows first.';
    } else {
        try {
            $pdo = get_pdo();
        } catch (Throwable $e) {
            $pdo      = null;
            $errorMsg = 'Database connection failed.';
        }

        if ($pdo !== null) {
            $nextSeq = next_customer_code_seq($pdo);
            foreach ($decoded as $i => $row) {
                $label = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if ($label === '') $label = '(row ' . ($i + 1) . ')';

                try {
                    $inserted = insert_customer_record($pdo, [
                        'first_name'        => (string)($row['first_name'] ?? ''),
                        'last_name'         => (string)($row['last_name'] ?? ''),
                        'mobile_no'         => (string)($row['mobile_no'] ?? ''),
                        'gender'            => (string)($row['gender'] ?? ''),
                        'permanent_address' => (string)($row['permanent_address'] ?? ''),
                    ], (string)($row['created_at'] ?? date('Y-m-d H:i:s')), $nextSeq);
                    $nextSeq++;
                    $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => true,
                                  'customer_id' => $inserted['customer_id'], 'code' => $inserted['code']];
                } catch (PDOException $e) {
                    $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => false, 'error' => $e->getMessage()];
                }
            }

            // A lone confirmed row keeps the old one-at-a-time feel
            $okRows = array_values(array_filter($results, fn($r) => $r['ok']));
            if ($source === 'single' && count($okRows) === 1 && count($results) === 1) {
                header('Location: customer_show.php?id=' . (int)$okRows[0]['customer_id'] . '&created=1');
                exit;
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_single'])) {
    // -----------------------------------------------------------
    // Step 2a: review a single typed-in record
    // -----------------------------------------------------------
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $mobile    = trim($_POST['mobile'] ?? '');
    $gender    = trim($_POST['gender'] ?? '');
    $address   = trim($_POST['permanent_address'] ?? '');

    $rows           = assemble_review_rows([[
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'mobile'     => $mobile,
        'gender'     => $gender,
        'address'    => $address,
    ]]);

    foreach ($rows as $row) {
        if ($row['state'] === 'invalid') {
            $formErrors = array_merge($formErrors, $row['reasons']);
        }
    }
    if ($formErrors !== []) {
        $mode = 'form'; // back to the form, values preserved above
    } else {
        $source = 'single';
        $mode   = 'preview';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_paste'])) {
    // -----------------------------------------------------------
    // Step 2b: review pasted spreadsheet rows
    // -----------------------------------------------------------
    $pasteText = (string)($_POST['paste_text'] ?? '');
    $rawRows   = build_raw_rows_from_paste($pasteText);

    if ($rawRows === []) {
        $errorMsg = 'No rows found — paste at least one non-empty line.';
        $mode     = 'form';
    } elseif (count($rawRows) > PASTE_MAX_ROWS) {
        $errorMsg = 'Too many rows (' . count($rawRows) . '). Split the paste into chunks of up to ' . PASTE_MAX_ROWS . ' rows.';
        $mode     = 'form';
    } else {
        $rows   = assemble_review_rows($rawRows);
        $source = 'batch';
        $mode   = 'preview';
    }
}

function val($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

$activeNav = 'add_passenger';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="index.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Customers</a>
</div>

<h4 class="mb-3">Add Passenger(s)</h4>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php if ($mode === 'form'): ?>

  <div class="card">
    <div class="card-header bg-white p-0 border-bottom-0">
      <ul class="nav nav-tabs card-header-tabs px-3 pt-2" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="single-tab" data-bs-toggle="tab" data-bs-target="#single-pane" type="button" role="tab" aria-controls="single-pane" aria-selected="true">
            Single Entry
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="paste-tab" data-bs-toggle="tab" data-bs-target="#paste-pane" type="button" role="tab" aria-controls="paste-pane" aria-selected="false">
            Paste from Spreadsheet
          </button>
        </li>
      </ul>
    </div>
    <div class="card-body">
      <div class="tab-content">

        <!-- Single Entry Tab -->
        <div class="tab-pane fade show active" id="single-pane" role="tabpanel" aria-labelledby="single-tab">
          <?php if (!empty($formErrors)): ?>
            <div class="alert alert-danger">
              <?php foreach ($formErrors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" class="row g-3">
            <div class="col-md-6">
              <label class="form-label">First Name *</label>
              <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($firstName) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Last Name *</label>
              <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($lastName) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Mobile *</label>
              <input type="text" name="mobile" class="form-control" placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($mobile) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-select">
                <option value="" <?= $gender === '' ? 'selected' : '' ?>>—</option>
                <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
                <option value="Other" <?= $gender === 'Other' ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Permanent Address</label>
              <textarea name="permanent_address" class="form-control" rows="2"><?= htmlspecialchars($address) ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2 pt-2">
              <button type="submit" name="review_single" value="1" class="btn btn-primary">Review &amp; Register</button>
              <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>

        <!-- Paste Tab -->
        <div class="tab-pane fade" id="paste-pane" role="tabpanel" aria-labelledby="paste-tab">
          <form method="post">
            <p class="text-muted small mb-2">
              Select rows in your registration spreadsheet, copy (Ctrl+C), and paste below. Columns are auto-detected.
            </p>
            <textarea id="paste_text" name="paste_text" class="form-control font-monospace" rows="10"
                      placeholder="Paste rows from your spreadsheet here (Ctrl+V)&#10;&#10;Works with or without a header row — extra columns are ignored automatically."><?= htmlspecialchars($pasteText) ?></textarea>
            <div class="form-text mt-2">
              • Columns are auto-detected — Timestamp, Consent, and other extra columns are ignored.<br>
              • Mobile numbers are auto-normalized (09xx… / 9xx… / +63… all accepted).<br>
              • Up to <?= PASTE_MAX_ROWS ?> rows per paste. Duplicates are flagged before saving.
            </div>
            <div class="d-flex gap-2 pt-3">
              <button type="submit" name="review_paste" value="1" class="btn btn-primary">Review Rows</button>
              <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>

      </div>
    </div>
  </div>

<?php elseif ($mode === 'preview'): ?>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-semibold">Review before registering</div>
        <div class="card-body">

          <?php
            $payloadRows = array_values(array_filter(array_column($rows, 'payload')));
            $okCount  = count(array_filter($rows, fn($r) => $r['state'] === 'ok' || $r['state'] === 'warn'));
            $dupCount = count(array_filter($rows, fn($r) => $r['state'] === 'duplicate'));
            $badCount = count(array_filter($rows, fn($r) => $r['state'] === 'invalid'));
          ?>
          <div class="alert <?= $badCount + $dupCount > 0 ? 'alert-warning' : 'alert-success' ?> mb-3">
            <strong><?= $okCount ?></strong> ready to register
            <?php if ($dupCount > 0): ?> · <strong><?= $dupCount ?></strong> duplicate(s) will be skipped<?php endif; ?>
            <?php if ($badCount > 0): ?> · <strong><?= $badCount ?></strong> invalid (excluded — fix and resubmit)<?php endif; ?>
          </div>

          <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-3">
            <thead>
              <tr>
                <th>#</th><th>First</th><th>Last</th><th>Mobile</th><th>Gender</th><th>Address</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $row): ?>
              <?php
                $m = $row['mapped'];
                [$badgeClass, $badgeText] = match ($row['state']) {
                    'ok'        => ['bg-success', 'OK'],
                    'warn'      => ['bg-warning text-dark', '⚠ ' . count($m['issues'])],
                    'duplicate' => ['bg-danger', 'Duplicate'],
                    default     => ['bg-danger', 'Invalid'],
                };
                $reasons = array_merge(
                    $row['state'] === 'warn' ? $m['issues'] : [],
                    $row['reasons']
                );
              ?>
              <tr class="<?= $row['state'] === 'invalid' || $row['state'] === 'duplicate' ? 'table-danger' : ($row['state'] === 'warn' ? 'table-warning' : '') ?>">
                <td><?= $i + 1 ?></td>
                <td><?= val($m['first_name']) ?></td>
                <td><?= val($m['last_name']) ?></td>
                <td class="text-truncate" style="max-width:140px"><?= val($m['mobile_no']) ?></td>
                <td><?= val($m['gender']) ?></td>
                <td class="text-truncate" style="max-width:180px" title="<?= htmlspecialchars($m['permanent_address']) ?>"><?= val($m['permanent_address']) ?></td>
                <td>
                  <span class="badge <?= $badgeClass ?>" title="<?= htmlspecialchars(implode('; ', $reasons)) ?>"><?= $badgeText ?></span>
                  <?php foreach (array_slice($reasons, 0, 2) as $reason): ?>
                    <div class="small text-danger"><?= htmlspecialchars($reason) ?></div>
                  <?php endforeach; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>

          <p class="text-muted small">
            Confirming creates one <code>customer</code> row plus one linked <code>top_ph_ekyc_details</code> row
            per passenger — identical to CSV imports and app registrations.
          </p>

          <?php if ($payloadRows !== []): ?>
          <form method="post" class="d-flex gap-2">
            <input type="hidden" name="confirm" value="1">
            <input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>">
            <textarea name="payload" style="display:none"><?= htmlspecialchars(json_encode($payloadRows), ENT_QUOTES, 'UTF-8') ?></textarea>
            <button type="submit" class="btn btn-success"
                    onclick="return confirm('Register <?= count($payloadRows) ?> passenger(s) into the database?');">
              Confirm &amp; Register <?= count($payloadRows) ?> Passenger<?= count($payloadRows) === 1 ? '' : '(s)' ?>
            </button>
            <a href="customer_create.php" class="btn btn-outline-secondary">Start Over</a>
          </form>
          <?php else: ?>
          <div class="alert alert-secondary mb-0">Nothing eligible to register — go back and fix or drop the flagged rows.</div>
          <a href="customer_create.php" class="btn btn-outline-secondary mt-2">Back</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<?php elseif ($mode === 'ingest'): ?>

  <?php
    $successCount = count(array_filter($results, fn($r) => $r['ok']));
    $failCount    = count($results) - $successCount;
  ?>

  <div class="alert <?= $failCount > 0 ? 'alert-warning' : 'alert-success' ?>">
    Registered <strong><?= $successCount ?></strong> passenger(s) successfully.
    <?php if ($failCount > 0): ?>
      <strong><?= $failCount ?></strong> row(s) failed — see details below.
    <?php endif; ?>
  </div>

  <div class="table-responsive bg-white">
    <table class="table table-striped table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Code</th>
          <th>Result</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td><?= $r['row'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['code'] ?? '—') ?></td>
            <td>
              <?php if ($r['ok']): ?>
                <span class="badge bg-success">Success</span>
              <?php else: ?>
                <span class="badge bg-danger">Failed</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['ok']): ?>
                <a href="customer_show.php?id=<?= (int)$r['customer_id'] ?>">View customer #<?= (int)$r['customer_id'] ?></a>
              <?php else: ?>
                <span class="text-danger small"><?= htmlspecialchars($r['error']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-3">
    <a href="customer_create.php" class="btn btn-primary">Add More Passenger(s)</a>
    <a href="index.php" class="btn btn-outline-secondary">Go to Customers</a>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
