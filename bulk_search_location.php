<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/geocoding.php';
require_once __DIR__ . '/includes/schema.php';

// JSON endpoint behind bulk_search.php's Location column. Called lazily by the
// page AFTER the table renders, so a slow geocoding provider never delays the
// search itself.
//
//   GET ?type=customer|rider&id=<id>
//
// Returns the cached current_address when present. Otherwise reverse-geocodes
// the record's coordinates once, stores the result on the row, and returns it.
// Failures degrade to ok:false so the page can show a placeholder; this
// endpoint never returns a 5xx that could break the table.
//
// Only the two tables bulk_search.php reads locations from are accepted, and
// the table name is picked from a fixed whitelist (never from raw input) while
// the id is bound as a parameter.

header('Content-Type: application/json');

$type = (string)($_GET['type'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

$tables = [
    'customer' => 'public.customer',
    'rider'    => 'public.riders',
];

if (!isset($tables[$type]) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'address' => null, 'cached' => false]);
    exit;
}
$table = $tables[$type];

try {
    $pdo = get_pdo();

    // current_address is a cache column added by sql/add_current_address.sql.
    // The helper adds it in place when a database hasn't run that migration
    // yet; if it stays unavailable we still resolve the label below, we just
    // can't cache it.
    $hasAddress = current_address_column_available($pdo, $table);
    $addrCol    = $hasAddress ? 'current_address' : 'NULL AS current_address';

    $stmt = $pdo->prepare(
        "SELECT current_lat, current_long, {$addrCol}
         FROM {$table}
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'address' => null, 'cached' => false]);
        exit;
    }

    // Cache hit - no API call.
    $address = trim((string)($row['current_address'] ?? ''));
    if ($address !== '') {
        echo json_encode(['ok' => true, 'address' => $address, 'cached' => true]);
        exit;
    }

    $lat = trim((string)($row['current_lat'] ?? ''));
    $lng = trim((string)($row['current_long'] ?? ''));
    if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
        // No usable fix - don't burn an API call on it.
        echo json_encode(['ok' => false, 'address' => null, 'cached' => false]);
        exit;
    }

    $label = reverse_geocode((float)$lat, (float)$lng);
    if ($label === null) {
        // Lookup failed. Leave the cache empty so a later visit can retry.
        echo json_encode(['ok' => false, 'address' => null, 'cached' => false]);
        exit;
    }

    if ($hasAddress) {
        $update = $pdo->prepare(
            "UPDATE {$table}
             SET current_address = :addr, updated_at = NOW()
             WHERE id = :id"
        );
        $update->bindValue(':addr', $label, PDO::PARAM_STR);
        $update->bindValue(':id', $id, PDO::PARAM_INT);
        $update->execute();
    }

    // cached=true only when we actually stored the label for reuse.
    echo json_encode(['ok' => true, 'address' => $label, 'cached' => $hasAddress]);
} catch (Throwable $e) {
    // Never surface provider/DB internals to the page.
    http_response_code(200);
    echo json_encode(['ok' => false, 'address' => null, 'cached' => false]);
}