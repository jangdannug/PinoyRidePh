<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rider_ingest.php';

$tabTitle = 'Add Driver(s)';

// Unified driver entry — same shape as the passenger page
// (customer_create.php): "Single entry" form and "Paste rows" from Excel /
// Google Sheets converge on one review -> confirm -> ingest pipeline that
// reuses insert_rider_record(), so anything registered here is structurally
// identical to CSV imports via import_rider.php.
//
// Duplicate mobile numbers are caught at REVIEW time — against the database
// AND within the submission itself — instead of after confirming.

const PASTE_MAX_ROWS = 500; // safety cap per submission

$mode       = 'form';   // form | preview | ingest
$errorMsg   = '';
$formErrors = [];
$rows       = [];
$results    = [];
$source     = 'single'; // single | batch
$pasteText  = '';

$f = [ // preserved single-form values
    'first_name' => '', 'last_name' => '', 'mobile' => '', 'email' => '',
    'address' => '', 'v_type_raw' => '', 'v_brand' => '', 'v_model' => '',
    'v_color' => '', 'v_plate_number' => '', 'drivers_license_img' => '',
    'v_or_cr_img' => '',
];

// ---- TSV parsing (Excel / Google Sheets clipboard format) ------------------
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
// Map pasted header cells to driver fields by name (any column order).
// Recognizes both the simplified headers (First Name, Last Name, Mobile...)
// and the actual Google Form spreadsheet headers (Made=model, Drivers
// License(Pro or Non Pro)=license img, OR/CR(Optional)=orcr img, etc.).
function map_rider_paste_columns(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $idx => $cell) {
        $raw = strtolower(trim((string)$cell));
        // Strip everything non-alpha for keyword matching
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
        } elseif (!isset($map['email']) && str_contains($h, 'email')) {
            $map['email'] = $idx;
        } elseif (!isset($map['address']) && str_contains($h, 'address') && !str_contains($h, 'email') && !str_contains($h, 'clearance') && !str_contains($h, 'authorization')) {
            // "Address" column — but not "Police Clearance (Opt Address)" or
            // "Authorization Letter..." or "Email Address"
            $map['address'] = $idx;
        } elseif (!isset($map['drivers_license_img']) && str_contains($h, 'licens') && !str_contains($h, 'authorization')) {
            // "Drivers License(Pro or Non Pro)" or "License Image" — the
            // Google Drive link or filename goes into drivers_license_img
            $map['drivers_license_img'] = $idx;
        } elseif (!isset($map['v_or_cr_img']) && (str_contains($h, 'orcr') || str_contains($raw, 'or/cr') || str_contains($raw, 'or cr'))) {
            // "OR/CR(Optional / To Follow Up)" or "Orcr Img"
            $map['v_or_cr_img'] = $idx;
        } elseif (!isset($map['v_type_raw']) && ($h === 'vehicletype' || $h === 'vtype' || str_contains($h, 'vehicletype') || (str_contains($h, 'vehicle') && str_contains($h, 'type')))) {
            $map['v_type_raw'] = $idx;
        } elseif (!isset($map['v_brand']) && (str_contains($h, 'brand') || ($h === 'make') || str_contains($raw, 'honda') || str_contains($raw, 'toyota'))) {
            // "Brand (Honda, Toyota, Mitsubishi, etc.)"
            $map['v_brand'] = $idx;
        } elseif (!isset($map['v_model']) && (str_contains($h, 'model') || str_contains($h, 'made') || str_contains($raw, 'click') || str_contains($raw, 'sniper') || str_contains($raw, 'vios'))) {
            // "Made(Click 125, Sniper, Vios, Avanza, etc.)" — this is the model column
            $map['v_model'] = $idx;
        } elseif (!isset($map['v_color']) && (str_contains($h, 'color') || str_contains($h, 'colour'))) {
            $map['v_color'] = $idx;
        } elseif (!isset($map['v_plate_number']) && str_contains($h, 'plate')) {
            $map['v_plate_number'] = $idx;
        }
    }
    return $map;
}

function looks_like_rider_header_row(array $row): bool
{
    $m = map_rider_paste_columns($row);
    // Need at least first_name + last_name + mobile to treat as a header row.
    // Also accept if >=4 fields are matched (the real spreadsheet has many
    // recognizable columns even if one keyword check misses).
    if (isset($m['first_name'], $m['last_name'], $m['mobile'])) {
        return true;
    }
    return count($m) >= 4;
}

// Headerless fallback order matches the actual Google Form spreadsheet
// (24 columns). Columns that we don't ingest are mapped to null (skipped).
//
// Index | Column
// 0     | Timestamp (skipped)
// 1     | Consent text (skipped)
// 2     | First Name
// 3     | Last Name
// 4     | Birth Date (skipped)
// 5     | Age (skipped)
// 6     | Gender (skipped)
// 7     | Vehicle Type
// 8     | Brand
// 9     | Made (= Model)
// 10    | Color
// 11    | Plate Number
// 12    | OR/CR
// 13    | Drivers License
// 14    | Police Clearance (skipped)
// 15    | Address
// 16    | Mobile Number
// 17    | PA AND CPC (skipped)
// 18    | Authorization Letter (skipped)
// 19    | Email Address
// 20    | Facebook link (skipped)
// 21    | Column 22 (skipped — must keep in spreadsheet)
// 22    | Column 23 (skipped — must keep in spreadsheet)
// 23    | Column 24 (skipped — must keep in spreadsheet)
function build_rider_rows_from_paste(string $text): array
{
    $records = parse_tsv($text);
    $records = array_values(array_filter(
        $records,
        fn(array $r): bool => array_filter(array_map(fn($c) => trim((string)$c), $r)) !== []
    ));
    if ($records === []) {
        return [];
    }

    $hasHead  = looks_like_rider_header_row($records[0]);
    $dataRows = $hasHead ? array_slice($records, 1) : $records;

    if ($hasHead) {
        $map = map_rider_paste_columns($records[0]);
    } else {
        // Full 24-column spreadsheet layout (0-indexed positions)
        $map = [
            'first_name'          => 2,
            'last_name'           => 3,
            'v_type_raw'          => 7,
            'v_brand'             => 8,
            'v_model'             => 9,
            'v_color'             => 10,
            'v_plate_number'      => 11,
            'v_or_cr_img'         => 12,
            'drivers_license_img' => 13,
            'address'             => 15,
            'mobile'              => 16,
            'email'               => 19,
        ];
    }

    $out = [];
    foreach ($dataRows as $r) {
        $get = fn(string $key): string => isset($map[$key]) ? trim((string)($r[$map[$key]] ?? '')) : '';
        $out[] = [
            'first_name'          => $get('first_name'),
            'last_name'           => $get('last_name'),
            'mobile'              => $get('mobile'),
            'email'               => $get('email'),
            'address'             => $get('address'),
            'v_type_raw'          => $get('v_type_raw'),
            'v_brand'             => $get('v_brand'),
            'v_model'             => $get('v_model'),
            'v_color'             => $get('v_color'),
            'v_plate_number'      => $get('v_plate_number'),
            'drivers_license_img' => $get('drivers_license_img'),
            'v_or_cr_img'         => $get('v_or_cr_img'),
        ];
    }
    return $out;
}

// Validate raw rows into reviewable rows. States:
//   ok | warn           -> included in the confirm payload
//   invalid | duplicate -> excluded, must be fixed or dropped before confirming
function assemble_review_rows_riders(array $rawRows): array
{
    $now = date('Y-m-d H:i:s');

    $rows = [];
    foreach ($rawRows as $raw) {
        $mapped = validate_rider_fields(
            $raw['first_name'],
            $raw['last_name'],
            $raw['mobile'],
            $raw['email'],
            $raw['address'],
            $raw['v_type_raw'],
            $raw['v_brand'],
            $raw['v_model'],
            $raw['v_color'],
            $raw['v_plate_number'],
            $raw['drivers_license_img'],
            $raw['v_or_cr_img']
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
    $existing = riders_mobiles_exist(get_pdo(), array_keys($candidates));

    $seenInBatch = [];
    foreach ($rows as &$row) {
        $mob = $row['mapped']['mobile_no'];
        if ($row['state'] === 'invalid' || $mob === '') {
            continue;
        }
        if (in_array($mob, $existing, true)) {
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
                'first_name'          => $row['mapped']['first_name'],
                'last_name'           => $row['mapped']['last_name'],
                'mobile_no'           => $row['mapped']['mobile_no'],
                'email_address'       => $row['mapped']['email_address'],
                'address'             => $row['mapped']['address'],
                'v_type_raw'          => $row['mapped']['v_type_raw'],
                'v_type'              => $row['mapped']['v_type'],
                'v_brand'             => $row['mapped']['v_brand'],
                'v_model'             => $row['mapped']['v_model'],
                'v_color'             => $row['mapped']['v_color'],
                'v_plate_number'      => $row['mapped']['v_plate_number'],
                'drivers_license_img' => $row['mapped']['drivers_license_img'],
                'v_or_cr_img'         => $row['mapped']['v_or_cr_img'],
                'created_at'          => $now,
            ];
        }
    }
    unset($row);

    return $rows;
}
$pdo = get_pdo();

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
        $nextSeq      = next_rider_code_seq($pdo);
        // Both wallet rows per rider draw from this same counter.
        $nextWalletSeq = next_wallet_ref_seq($pdo);

        foreach ($decoded as $i => $row) {
            $label = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($label === '') $label = '(row ' . ($i + 1) . ')';

            // Re-apply the image length cap here too, in case the hidden
            // "payload" field was tampered with between preview and submit.
            [$driversLicenseImg] = rider_cap_image_value((string)($row['drivers_license_img'] ?? ''));
            [$vOrCrImg]          = rider_cap_image_value((string)($row['v_or_cr_img'] ?? ''));

            $mobileNo = (string)($row['mobile_no'] ?? '');
            if (rider_exists_by_mobile($pdo, $mobileNo)) {
                $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => false, 'error' => 'Mobile number already exists — skipped'];
                continue;
            }

            try {
                $inserted = insert_rider_record($pdo, [
                    'first_name'          => (string)($row['first_name'] ?? ''),
                    'last_name'           => (string)($row['last_name'] ?? ''),
                    'mobile_no'           => $mobileNo,
                    'email_address'       => (string)($row['email_address'] ?? ''),
                    'address'             => (string)($row['address'] ?? ''),
                    'v_type_raw'          => (string)($row['v_type_raw'] ?? ''),
                    'v_type'              => interpret_vehicle_type((string)($row['v_type_raw'] ?? '')),
                    'v_brand'             => (string)($row['v_brand'] ?? ''),
                    'v_model'             => (string)($row['v_model'] ?? ''),
                    'v_color'             => (string)($row['v_color'] ?? ''),
                    'v_plate_number'      => (string)($row['v_plate_number'] ?? ''),
                    'drivers_license_img' => $driversLicenseImg,
                    'v_or_cr_img'         => $vOrCrImg,
                ], (string)($row['created_at'] ?? date('Y-m-d H:i:s')), $nextSeq, $nextWalletSeq);
                $nextSeq++;
                $nextWalletSeq += 2;
                log_activity('create_driver', 'rider', (string)$inserted['rider_id'], $inserted['code'] . ' - ' . $label);
                $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => true,
                              'rider_id' => $inserted['rider_id'], 'code' => $inserted['code']];
            } catch (PDOException $e) {
                $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        // A lone confirmed row keeps the old one-at-a-time feel
        $okRows = array_values(array_filter($results, fn($r) => $r['ok']));
        if ($source === 'single' && count($okRows) === 1 && count($results) === 1) {
            header('Location: rider_show.php?id=' . (int)$okRows[0]['rider_id']);
            exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_single'])) {
    // -----------------------------------------------------------
    // Step 2a: review a single typed-in record
    // -----------------------------------------------------------
    foreach (array_keys($f) as $k) {
        $f[$k] = trim($_POST[$k] ?? '');
    }

    $rows           = assemble_review_rows_riders([$f]);
    $blockingIssues = [];
    foreach ($rows as $row) {
        if ($row['state'] === 'invalid') {
            $blockingIssues = array_merge($blockingIssues, $row['reasons']);
        }
    }
    if ($blockingIssues !== []) {
        $formErrors = $blockingIssues;
        $mode       = 'form'; // back to the form, values preserved above
    } else {
        $source = 'single';
        $mode   = 'preview';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_paste'])) {
    // -----------------------------------------------------------
    // Step 2b: review pasted spreadsheet rows
    // -----------------------------------------------------------
    $pasteText = (string)($_POST['paste_text'] ?? '');
    $rawRows   = build_rider_rows_from_paste($pasteText);

    if ($rawRows === []) {
        $errorMsg = 'No rows found — paste at least one non-empty line.';
        $mode     = 'form';
    } elseif (count($rawRows) > PASTE_MAX_ROWS) {
        $errorMsg = 'Too many rows (' . count($rawRows) . '). Split the paste into chunks of up to ' . PASTE_MAX_ROWS . ' rows.';
        $mode     = 'form';
    } else {
        $rows   = assemble_review_rows_riders($rawRows);
        $source = 'batch';
        $mode   = 'preview';
    }
}
function val($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

$activeNav = 'add_driver';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="riders.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Drivers</a>
</div>

<h4 class="mb-3">Add Driver(s)</h4>

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
              <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($f['first_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Last Name *</label>
              <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($f['last_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Mobile *</label>
              <input type="text" name="mobile" class="form-control" placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($f['mobile']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="text" name="email" class="form-control" value="<?= htmlspecialchars($f['email']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Vehicle Type</label>
              <select name="v_type_raw" class="form-select">
                <option value="Motorcycle" <?= $f['v_type_raw'] === 'Motorcycle' ? 'selected' : '' ?>>Motorcycle</option>
                <option value="Tricycle" <?= $f['v_type_raw'] === 'Tricycle' ? 'selected' : '' ?>>Tricycle</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Plate Number</label>
              <input type="text" name="v_plate_number" class="form-control" value="<?= htmlspecialchars($f['v_plate_number']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Brand</label>
              <input type="text" name="v_brand" class="form-control" value="<?= htmlspecialchars($f['v_brand']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Model</label>
              <input type="text" name="v_model" class="form-control" value="<?= htmlspecialchars($f['v_model']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Color</label>
              <input type="text" name="v_color" class="form-control" value="<?= htmlspecialchars($f['v_color']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($f['address']) ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Driver's License Image</label>
              <input type="text" name="drivers_license_img" class="form-control" placeholder="filename.jpg or link" value="<?= htmlspecialchars($f['drivers_license_img']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">OR/CR Image</label>
              <input type="text" name="v_or_cr_img" class="form-control" placeholder="filename.jpg or link" value="<?= htmlspecialchars($f['v_or_cr_img']) ?>">
            </div>
            <div class="col-12 d-flex gap-2 pt-2">
              <button type="submit" name="review_single" value="1" class="btn btn-primary">Review &amp; Register</button>
              <a href="riders.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>

        <!-- Paste Tab -->
        <div class="tab-pane fade" id="paste-pane" role="tabpanel" aria-labelledby="paste-tab">
          <form method="post">
            <p class="text-muted small mb-2">
              Select rows in your driver registration spreadsheet, copy (Ctrl+C), and paste below. Columns are auto-detected.
            </p>
            <textarea id="paste_text" name="paste_text" class="form-control font-monospace" rows="10"
                      placeholder="Paste rows from your spreadsheet here (Ctrl+V)&#10;&#10;Works with or without a header row — extra columns (Timestamp, Consent, Birth Date, Age, etc.) are ignored automatically."><?= htmlspecialchars($pasteText) ?></textarea>
            <div class="form-text mt-2">
              • Columns are auto-detected — extra columns (Timestamp, Consent, Birth Date, Age, Gender, Police Clearance, etc.) are ignored.<br>
              • Mobile numbers are auto-normalized (09xx… / 9xx… / +63… all accepted).<br>
              • Up to <?= PASTE_MAX_ROWS ?> rows per paste. Duplicates are flagged before saving.
            </div>
            <div class="d-flex gap-2 pt-3">
              <button type="submit" name="review_paste" value="1" class="btn btn-primary">Review Rows</button>
              <a href="riders.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>

      </div>
    </div>
  </div>

<?php elseif ($mode === 'preview'): ?>

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
            <th>#</th><th>First</th><th>Last</th><th>Mobile</th><th>Vehicle</th><th>Email</th><th>Address</th><th>Status</th>
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
            $vehicle = trim(implode(' ', array_filter([
                $m['v_type_raw'],
                $m['v_brand'],
                $m['v_model'],
                $m['v_color'],
                $m['v_plate_number'] !== '' ? '(' . $m['v_plate_number'] . ')' : '',
            ])));
          ?>
          <tr class="<?= $row['state'] === 'invalid' || $row['state'] === 'duplicate' ? 'table-danger' : ($row['state'] === 'warn' ? 'table-warning' : '') ?>">
            <td><?= $i + 1 ?></td>
            <td><?= val($m['first_name']) ?></td>
            <td><?= val($m['last_name']) ?></td>
            <td class="text-truncate" style="max-width:140px"><?= val($m['mobile_no']) ?></td>
            <td class="text-truncate" style="max-width:200px" title="<?= htmlspecialchars($vehicle) ?>"><?= val($vehicle) ?></td>
            <td class="text-truncate" style="max-width:160px"><?= val($m['email_address']) ?></td>
            <td class="text-truncate" style="max-width:160px" title="<?= htmlspecialchars($m['address']) ?>"><?= val($m['address']) ?></td>
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
        Confirming creates one <code>riders</code> row plus its vehicle, address, linked
        <code>top_ph_ekyc_details</code> row and both wallet rows — identical to CSV imports.
      </p>

      <?php if ($payloadRows !== []): ?>
      <form method="post" class="d-flex gap-2">
        <input type="hidden" name="confirm" value="1">
        <input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>">
        <textarea name="payload" style="display:none"><?= htmlspecialchars(json_encode($payloadRows), ENT_QUOTES, 'UTF-8') ?></textarea>
        <button type="submit" class="btn btn-success"
                onclick="return confirm('Register <?= count($payloadRows) ?> driver(s) into the database?');">
          Confirm &amp; Register <?= count($payloadRows) ?> Driver<?= count($payloadRows) === 1 ? '' : '(s)' ?>
        </button>
        <a href="rider_create.php" class="btn btn-outline-secondary">Start Over</a>
      </form>
      <?php else: ?>
      <div class="alert alert-secondary mb-0">Nothing eligible to register — go back and fix or drop the flagged rows.</div>
      <a href="rider_create.php" class="btn btn-outline-secondary mt-2">Back</a>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($mode === 'ingest'): ?>

  <?php
    $successCount = count(array_filter($results, fn($r) => $r['ok']));
    $failCount    = count($results) - $successCount;
  ?>

  <div class="alert <?= $failCount > 0 ? 'alert-warning' : 'alert-success' ?>">
    Registered <strong><?= $successCount ?></strong> driver(s) successfully.
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
                <a href="rider_show.php?id=<?= (int)$r['rider_id'] ?>">View driver #<?= (int)$r['rider_id'] ?></a>
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
    <a href="rider_create.php" class="btn btn-primary">Add More Driver(s)</a>
    <a href="riders.php" class="btn btn-outline-secondary">Go to Drivers</a>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
