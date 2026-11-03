BEGIN;

CREATE TABLE IF NOT EXISTS session_presentations (
    id          BIGSERIAL    PRIMARY KEY,
    session_id  INTEGER      NOT NULL REFERENCES event_sessions(id) ON DELETE CASCADE,
    presenter   VARCHAR(150) NOT NULL,
    topic       VARCHAR(255) NOT NULL,
    start_time  TIME         NOT NULL,
    end_time    TIME         NOT NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_presentation_times CHECK (end_time > start_time)
);

CREATE INDEX IF NOT EXISTS idx_session_presentations_session
    ON session_presentations (session_id, start_time);

COMMIT;
