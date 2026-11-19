-- The KVB register holds some VT numbers with 4 digits (for example VTC0857).
-- Accept them alongside the 5-digit form. Safe to run more than once.
BEGIN;
ALTER TABLE participants DROP CONSTRAINT IF EXISTS chk_reg_no_format;
ALTER TABLE participants ADD CONSTRAINT chk_reg_no_format CHECK (reg_no ~ '^(\d{4,5}|VT[ABC]\d{4,5})$');
COMMIT;
