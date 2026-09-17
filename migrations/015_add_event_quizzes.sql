BEGIN;

CREATE TABLE event_quiz_questions (
    id                SERIAL PRIMARY KEY,
    event_id          INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    session_window_id INTEGER NOT NULL REFERENCES session_windows(id),
    question_text     TEXT NOT NULL,
    sort_order        INTEGER NOT NULL DEFAULT 0,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE event_quiz_options (
    id           SERIAL PRIMARY KEY,
    question_id  INTEGER NOT NULL REFERENCES event_quiz_questions(id) ON DELETE CASCADE,
    option_text  TEXT NOT NULL,
    is_correct   BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order   INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE quiz_attempts (
    id                 SERIAL PRIMARY KEY,
    registration_id    INTEGER NOT NULL REFERENCES registrations(id) ON DELETE CASCADE,
    session_window_id  INTEGER NOT NULL REFERENCES session_windows(id),
    attempt_number     SMALLINT NOT NULL,
    score_percent      NUMERIC(5,2) NOT NULL,
    passed             BOOLEAN NOT NULL DEFAULT FALSE,
    answers            JSONB NOT NULL,
    submitted_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (registration_id, session_window_id, attempt_number)
);

CREATE INDEX idx_quiz_questions_event_session ON event_quiz_questions(event_id, session_window_id);
CREATE INDEX idx_quiz_attempts_reg_session ON quiz_attempts(registration_id, session_window_id);

COMMIT;
