-- The audit table that keeps each ID number as it was before a correction.
-- It was first created by the one-off correction tool on the live server; this
-- makes a new database get the same table. Safe to run on a database that has it.
BEGIN;
CREATE TABLE IF NOT EXISTS participant_id_fixes (
    id        BIGSERIAL PRIMARY KEY,
    reg_no    VARCHAR(10) NOT NULL,
    old_id    VARCHAR(20) NOT NULL,
    new_id    VARCHAR(20) NOT NULL,
    fixed_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_participant_id_fixes_reg_no ON participant_id_fixes (reg_no);
COMMIT;
