BEGIN;

ALTER TABLE attendance ADD CONSTRAINT uq_attendance_slot_session_id UNIQUE (registration_id, attendance_date, session_id);

COMMIT;
