BEGIN;
ALTER TABLE events DROP COLUMN points_morning;
ALTER TABLE events DROP COLUMN points_mid_morning;
ALTER TABLE events DROP COLUMN points_afternoon;
COMMIT;
