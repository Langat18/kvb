BEGIN;

CREATE TABLE event_sessions (
    id               SERIAL PRIMARY KEY,
    event_id         BIGINT NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    label            VARCHAR(50) NOT NULL,
    start_time       TIME NOT NULL,
    end_time         TIME NOT NULL,
    points           SMALLINT NOT NULL DEFAULT 0,
    sort_order       SMALLINT NOT NULL DEFAULT 0,
    active           BOOLEAN NOT NULL DEFAULT TRUE,
    source_item_key  VARCHAR(30),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_event_session_times CHECK (end_time > start_time)
);

INSERT INTO event_sessions (event_id, label, start_time, end_time, points, sort_order, active, source_item_key)
SELECT e.id, sw.label, sw.start_time, sw.end_time, COALESCE(esp.points, 0), sw.sort_order, sw.active, sw.item_key
FROM events e
CROSS JOIN session_windows sw
LEFT JOIN event_session_points esp ON esp.event_id = e.id AND esp.session_window_id = sw.id;

ALTER TABLE attendance ADD COLUMN session_id INTEGER REFERENCES event_sessions(id);

UPDATE attendance a
SET session_id = es.id
FROM registrations r, event_sessions es
WHERE a.registration_id = r.id
  AND es.event_id = r.event_id
  AND es.source_item_key = a.session::text;

ALTER TABLE event_quiz_questions ADD COLUMN session_id INTEGER REFERENCES event_sessions(id);

UPDATE event_quiz_questions eqq
SET session_id = es.id
FROM session_windows sw, event_sessions es
WHERE eqq.session_window_id = sw.id
  AND es.event_id = eqq.event_id
  AND es.source_item_key = sw.item_key;

ALTER TABLE quiz_attempts ADD COLUMN session_id INTEGER REFERENCES event_sessions(id);

UPDATE quiz_attempts qa
SET session_id = es.id
FROM registrations r, session_windows sw, event_sessions es
WHERE qa.registration_id = r.id
  AND qa.session_window_id = sw.id
  AND es.event_id = r.event_id
  AND es.source_item_key = sw.item_key;

COMMIT;
