BEGIN;

CREATE TABLE event_session_points (
    id                SERIAL PRIMARY KEY,
    event_id          INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    session_window_id INTEGER NOT NULL REFERENCES session_windows(id),
    points            SMALLINT NOT NULL DEFAULT 0,
    UNIQUE (event_id, session_window_id)
);

INSERT INTO event_session_points (event_id, session_window_id, points)
SELECT e.id, sw.id, e.points_morning
FROM events e JOIN session_windows sw ON sw.item_key = 'morning'
WHERE e.points_morning IS NOT NULL AND e.points_morning > 0;

INSERT INTO event_session_points (event_id, session_window_id, points)
SELECT e.id, sw.id, e.points_mid_morning
FROM events e JOIN session_windows sw ON sw.item_key = 'mid_morning'
WHERE e.points_mid_morning IS NOT NULL AND e.points_mid_morning > 0;

INSERT INTO event_session_points (event_id, session_window_id, points)
SELECT e.id, sw.id, e.points_afternoon
FROM events e JOIN session_windows sw ON sw.item_key = 'afternoon'
WHERE e.points_afternoon IS NOT NULL AND e.points_afternoon > 0;

COMMIT;
