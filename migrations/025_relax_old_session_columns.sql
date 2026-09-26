BEGIN;

ALTER TABLE attendance ALTER COLUMN session DROP NOT NULL;
ALTER TABLE event_quiz_questions ALTER COLUMN session_window_id DROP NOT NULL;
ALTER TABLE quiz_attempts ALTER COLUMN session_window_id DROP NOT NULL;

COMMIT;
