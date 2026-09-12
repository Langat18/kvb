
BEGIN;

ALTER TABLE events ADD COLUMN event_mode_id INTEGER REFERENCES event_modes(id);

UPDATE events e
SET event_mode_id = m.id
FROM event_modes m
WHERE m.item_key = e.event_mode::text;

COMMIT;
