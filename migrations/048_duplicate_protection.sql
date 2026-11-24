-- Makes double entries impossible at the database level, not only in the application:
--   * one points row per sign-in
--   * one live PMS upload per PMS number per event
-- If duplicates already exist the migration stops with a message, and nothing is changed.
BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM points_ledger WHERE attendance_id IS NOT NULL GROUP BY attendance_id HAVING count(*) > 1) THEN
        RAISE EXCEPTION 'points_ledger already has more than one row for the same sign-in. Review them before adding this rule.';
    END IF;
    IF EXISTS (SELECT 1 FROM pms_points_sends WHERE status <> 'failed' GROUP BY event_id, pms_reg_no HAVING count(*) > 1) THEN
        RAISE EXCEPTION 'pms_points_sends already has two live uploads for the same PMS number in one event. Review them before adding this rule.';
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uq_points_ledger_attendance
    ON points_ledger (attendance_id) WHERE attendance_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_pms_points_sends_pms_number
    ON pms_points_sends (event_id, pms_reg_no) WHERE status <> 'failed';

COMMIT;
