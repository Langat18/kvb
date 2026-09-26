BEGIN;

INSERT INTO permissions (item_key, label, description) VALUES
    ('record_manual_attendance', 'Record Manual Attendance', 'Manually record attendance for an attendee without a working device, using staff device geolocation');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'admin' AND p.item_key = 'record_manual_attendance';

ALTER TABLE attendance ADD COLUMN recorded_by_staff_id BIGINT REFERENCES staff(id);

COMMIT;
