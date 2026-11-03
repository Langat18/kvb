BEGIN;

ALTER TABLE event_sessions
    ADD COLUMN IF NOT EXISTS signin_lead_minutes  SMALLINT,
    ADD COLUMN IF NOT EXISTS signin_grace_minutes SMALLINT;

ALTER TABLE event_sessions DROP CONSTRAINT IF EXISTS chk_session_signin_minutes;
ALTER TABLE event_sessions ADD CONSTRAINT chk_session_signin_minutes CHECK (
    (signin_lead_minutes  IS NULL OR signin_lead_minutes  BETWEEN 1 AND 180) AND
    (signin_grace_minutes IS NULL OR signin_grace_minutes BETWEEN 0 AND 180)
);

COMMIT;
