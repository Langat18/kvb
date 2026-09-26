BEGIN;

ALTER TABLE attendance ADD COLUMN device_id VARCHAR(64);
CREATE INDEX idx_attendance_device_session ON attendance (session_id, device_id) WHERE device_id IS NOT NULL;

COMMIT;
