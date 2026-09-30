BEGIN;

ALTER TABLE event_sessions ADD COLUMN session_date DATE;
CREATE INDEX idx_event_sessions_event_date ON event_sessions (event_id, session_date);

COMMIT;
