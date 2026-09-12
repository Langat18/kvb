BEGIN;

INSERT INTO permissions (item_key, label, description) VALUES
    ('manage_events', 'Manage Events', 'Edit any event, not just ones you created');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'admin' AND p.item_key = 'manage_events';

COMMIT;
