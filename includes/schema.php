<?php
declare(strict_types=1);

// Self-healing schema helper for the bulk-search "Location" cache column.
//
// bulk_search.php and bulk_search_location.php read/write `current_address`
// on public.customer and public.riders. That column is added by
// sql/add_current_address.sql, but a database that hasn't run that migration
// would otherwise fatal the whole page on the SELECT
// ("column \"current_address\" does not exist").
//
// current_address_column_available() checks the catalog once per table per
// process and, when the column is missing, adds it with the exact same
// idempotent DDL the migration uses. If the connection role lacks ALTER
// rights (or anything else goes wrong) it returns false so callers degrade
// gracefully instead of crashing.

// True when public.<table>.<column> exists. Generic on purpose so future
// optional columns can reuse it; identifiers are always bound as parameters
// here (they're values in information_schema, never interpolated SQL).
function schema_column_exists(PDO $pdo, string $schema, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = :s
           AND table_name   = :t
           AND column_name  = :c
         LIMIT 1"
    );
    $stmt->execute([':s' => $schema, ':t' => $table, ':c' => $column]);
    return $stmt->fetchColumn() !== false;
}

// True when the current connection may ALTER public.<table> — it owns the
// table, or the role is a superuser. ALTER TABLE otherwise fails with
// "must be owner of table", which the server logs as an ERROR; checking first
// keeps a read-only app role from firing a doomed ALTER on every request.
function schema_can_alter(PDO $pdo, string $schema, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT pg_catalog.pg_get_userbyid(c.relowner) = current_user
                OR EXISTS (
                     SELECT 1 FROM pg_catalog.pg_roles r
                     WHERE r.rolname = current_user AND r.rolsuper
                   )
         FROM pg_catalog.pg_class c
         JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = :s AND c.relname = :t
         LIMIT 1"
    );
    $stmt->execute([':s' => $schema, ':t' => $table]);
    return (bool)$stmt->fetchColumn();
}

// Returns true when the table has (or just gained) its current_address cache
// column, false when it is unavailable and callers must degrade. Only the two
// tables bulk_search.php reads locations from are accepted; the table name is
// picked from this fixed whitelist before it is interpolated into DDL.
function current_address_column_available(PDO $pdo, string $table): bool
{
    static $allowed = [
        'public.customer' => true,
        'public.riders'   => true,
    ];
    if (!isset($allowed[$table])) {
        return false;
    }

    // Check/ALTER once per table per request.
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $dot       = strrpos($table, '.');
    $tableName = $dot === false ? $table : substr($table, $dot + 1);

    try {
        if (schema_column_exists($pdo, 'public', $tableName, 'current_address')) {
            return $cache[$table] = true;
        }

        // Column is missing. Only try to add it when this connection actually
        // may (table owner or superuser) — otherwise the ALTER is guaranteed to
        // fail and would spam the server log on every request.
        if (!schema_can_alter($pdo, 'public', $tableName)) {
            return $cache[$table] = false;
        }

        // Apply the migration in place. ADD COLUMN IF NOT EXISTS is a no-op if
        // a concurrent request already added it, and adding a NULL varchar
        // column without a default is a cheap metadata-only change.
        $pdo->exec(
            "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS current_address varchar(255)"
        );

        return $cache[$table] = schema_column_exists($pdo, 'public', $tableName, 'current_address');
    } catch (Throwable $e) {
        // Read-only role, missing privileges, locked table, etc. Callers fall
        // back to selecting NULL / skipping the cache instead of erroring.
        return $cache[$table] = false;
    }
}