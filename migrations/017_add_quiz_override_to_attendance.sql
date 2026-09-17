BEGIN;

-- Records a supervisor's decision to release points for a check-in that
-- failed the session quiz after exhausting both attempts. Scoped to this
-- specific attendance row (one day, one session)
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS quiz_override_by INTEGER REFERENCES users(id);
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS quiz_override_at TIMESTAMPTZ;

COMMIT;
