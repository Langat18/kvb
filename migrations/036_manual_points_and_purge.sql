BEGIN;

ALTER TABLE points_ledger
    ADD COLUMN IF NOT EXISTS session_id INTEGER REFERENCES event_sessions(id),
    ADD COLUMN IF NOT EXISTS awarded_by INTEGER REFERENCES staff(id) ON DELETE SET NULL;

ALTER TABLE points_ledger DROP CONSTRAINT IF EXISTS chk_points_ledger_kind;
ALTER TABLE points_ledger ADD CONSTRAINT chk_points_ledger_kind CHECK (
    (kind = 'attendance'      AND attendance_id IS NOT NULL AND bonus_date IS NULL) OR
    (kind = 'presenter_bonus' AND attendance_id IS NULL AND bonus_date IS NOT NULL) OR
    (kind = 'manual_award'    AND attendance_id IS NULL AND bonus_date IS NOT NULL AND session_id IS NOT NULL)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_points_manual_award
    ON points_ledger (reg_no, event_id, session_id, bonus_date) WHERE kind = 'manual_award';

CREATE TABLE IF NOT EXISTS registration_deletions (
    id               BIGSERIAL    PRIMARY KEY,
    event_id         BIGINT       REFERENCES events(id) ON DELETE SET NULL,
    event_title      VARCHAR(255) NOT NULL,
    reg_no           VARCHAR(10)  NOT NULL,
    participant      VARCHAR(255) NOT NULL,
    attendance_count INTEGER      NOT NULL,
    points_total     INTEGER      NOT NULL,
    snapshot         JSONB        NOT NULL,
    reason           VARCHAR(255) NOT NULL,
    deleted_by       INTEGER      REFERENCES staff(id) ON DELETE SET NULL,
    deleted_at       TIMESTAMPTZ  NOT NULL DEFAULT now()
);

INSERT INTO permissions (item_key, label, description) VALUES
    ('award_manual_points',  'Award Manual Points',  'Add or remove manually awarded points for a session'),
    ('delete_registrations', 'Delete Registrations', 'Permanently remove an attendee registration from an event')
ON CONFLICT (item_key) DO NOTHING;

COMMIT;
