-- The first schema required map coordinates unless the old event_mode column
-- said 'virtual'. Event modes now live in the event_modes lookup table and the
-- old column is no longer written, so the rule rejected every virtual event.
-- The app checks location and meeting link itself (Event::validate).
BEGIN;
ALTER TABLE events DROP CONSTRAINT IF EXISTS chk_geo_required_for_onsite;
COMMIT;
