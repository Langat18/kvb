BEGIN;

ALTER TABLE points_ledger ALTER COLUMN attendance_id DROP NOT NULL;

ALTER TABLE points_ledger
    ADD COLUMN IF NOT EXISTS kind       VARCHAR(20) NOT NULL DEFAULT 'attendance',
    ADD COLUMN IF NOT EXISTS bonus_date DATE;

ALTER TABLE points_ledger DROP CONSTRAINT IF EXISTS chk_points_ledger_kind;
ALTER TABLE points_ledger ADD CONSTRAINT chk_points_ledger_kind CHECK (
    (kind = 'attendance'      AND attendance_id IS NOT NULL AND bonus_date IS NULL) OR
    (kind = 'presenter_bonus' AND attendance_id IS NULL     AND bonus_date IS NOT NULL)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_points_presenter_bonus
    ON points_ledger (reg_no, event_id, bonus_date) WHERE kind = 'presenter_bonus';

ALTER TABLE session_presentations
    ADD COLUMN IF NOT EXISTS reg_no VARCHAR(10) REFERENCES participants(reg_no) ON UPDATE CASCADE ON DELETE SET NULL;

COMMIT;
