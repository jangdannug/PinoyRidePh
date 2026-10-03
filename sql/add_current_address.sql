-- -------------------------------------------------------------------
-- Cached reverse-geocoded location label for bulk search results
-- -------------------------------------------------------------------
-- bulk_search.php shows a "Location" column built from current_lat /
-- current_long. Those are opaque coordinate pairs, so this adds a cached,
-- human-readable label ("Makati City, Metro Manila") resolved once and
-- then reused, instead of calling a geocoding API on every page load.
--
-- The column lives on BOTH tables the Location column reads from:
--   public.customer  - passenger rows (current_lat / current_long)
--   public.riders    - driver rows   (current_lat / current_long)
--
-- NULL/'' simply means "not resolved yet" - the page shows a placeholder and
-- resolves it asynchronously on first view. Safe to re-run.
alter table public.customer
    add column if not exists current_address varchar(255);

alter table public.riders
    add column if not exists current_address varchar(255);